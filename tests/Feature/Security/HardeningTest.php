<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Console\Commands\RotateAppKey;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Notifications\Models\NotificationRule;
use App\Domain\Secrets\Enums\SecretScope;
use App\Domain\Secrets\Models\Secret;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_carry_the_security_headers_and_a_nonce(): void
    {
        $response = $this->actingAs(User::factory()->create())->get('/')->assertOk()
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
        $this->assertSame(1, preg_match("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $csp, $m));
        $this->assertStringNotContainsString("script-src 'self' 'unsafe-inline'", $csp);
        // Every inline script on the page carries the nonce of the header.
        preg_match_all('/<script\b(?![^>]*\bsrc=)[^>]*>/', (string) $response->getContent(), $inline);
        $this->assertNotEmpty($inline[0]);
        foreach ($inline[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$m[1].'"', $tag, $tag);
        }
        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function test_hsts_is_sent_over_https_and_report_only_is_available(): void
    {
        config(['jogger.csp_report_only' => true]);
        $response = $this->actingAs(User::factory()->create())->get('https://localhost/');

        $this->assertNotNull($response->headers->get('Strict-Transport-Security'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
        $this->assertNull($response->headers->get('Content-Security-Policy'));
    }

    public function test_machine_endpoints_refuse_cross_origin_browser_requests(): void
    {
        User::factory()->admin()->create();

        $this->postJson('/webhooks/github', [], ['Origin' => 'https://evil.example'])->assertForbidden();
        $this->postJson('/agent/v1/heartbeat', [], ['Sec-Fetch-Site' => 'cross-site'])->assertForbidden();
        // Without browser markers the request reaches authentication and fails there instead.
        $this->postJson('/agent/v1/heartbeat', [])->assertUnauthorized();
        // CSRF still guards session routes.
        $this->assertContains(PreventRequestForgery::class, app(Kernel::class)->getMiddlewareGroups()['web']);
    }

    public function test_rotating_the_key_re_encrypts_everything_and_the_app_reads_it_with_the_new_key(): void
    {
        $app = GithubApp::factory()->create(['private_key' => 'PRIVATE-KEY', 'webhook_secret' => 'hook-secret']);
        $secret = Secret::query()->create(['scope_type' => SecretScope::Global, 'kind' => 'env', 'name' => 'TOKEN', 'value' => 'secret-value']);
        $rule = NotificationRule::query()->create(['channel' => 'webhook', 'target' => 'https://x.example', 'secret' => 'sign']);
        $user = User::factory()->create();
        DB::table('users')->where('id', $user->id)->update(['two_factor_secret' => encrypt('totp-seed')]);

        $newKey = 'base64:'.base64_encode(Encrypter::generateKey((string) config('app.cipher')));
        $before = DB::table('secrets')->value('value');
        $this->artisan('jogger:rotate-key', ['--new-key' => $newKey])->assertSuccessful();
        $this->assertNotSame($before, DB::table('secrets')->value('value'));

        // What every process does after the restart: use the new key.
        config(['app.key' => $newKey]);
        Crypt::swap(new Encrypter(base64_decode(substr($newKey, 7)), (string) config('app.cipher')));
        Model::encryptUsing(Crypt::getFacadeRoot());

        $this->assertSame('PRIVATE-KEY', $app->fresh()->private_key);
        $this->assertSame('hook-secret', $app->fresh()->webhook_secret);
        $this->assertSame('secret-value', $secret->fresh()->value);
        $this->assertSame('sign', $rule->fresh()->secret);
        $this->assertSame('totp-seed', decrypt(DB::table('users')->where('id', $user->id)->value('two_factor_secret')));
    }

    public function test_every_encrypted_cast_is_covered_by_the_rotation(): void
    {
        $covered = [];
        foreach (RotateAppKey::COLUMNS as $table => [, $columns]) {
            foreach ($columns as $column) {
                $this->assertTrue(Schema::hasColumn($table, $column), "{$table}.{$column}");
                $covered[] = "{$table}.{$column}";
            }
        }
        foreach ([GithubApp::class, Secret::class, NotificationRule::class] as $class) {
            $model = new $class;
            foreach ($model->getCasts() as $column => $cast) {
                if ($cast === 'encrypted') {
                    $this->assertContains($model->getTable().'.'.$column, $covered);
                }
            }
        }
        $this->assertSame(0, Artisan::call('list'));
    }

    public function test_forwarded_headers_are_trusted_only_from_private_proxies(): void
    {
        $handle = function (string $remote): Request {
            $request = Request::create('/up', 'GET', server: [
                'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'REMOTE_ADDR' => $remote,
            ]);
            $this->app->make(Kernel::class)->handle($request);

            return $request;
        };

        $viaProxy = $handle('172.18.0.5');
        $this->assertTrue($viaProxy->isSecure());
        $this->assertSame('203.0.113.9', $viaProxy->ip());

        $direct = $handle('198.51.100.7');
        $this->assertFalse($direct->isSecure());
        $this->assertSame('198.51.100.7', $direct->ip());
    }
}
