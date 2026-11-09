<?php

declare(strict_types=1);

namespace Tests\Feature\Github;

use App\Domain\Access\Models\AuditLog;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Support\WebhookReachability;
use App\Livewire\Github\Connect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ConnectPageTest extends TestCase
{
    use RefreshDatabase;

    private static ?string $pem = null;

    protected function setUp(): void
    {
        parent::setUp();

        self::$pem ??= (string) GithubApp::factory()->withRealKey()->make()->private_key;
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    // ---------------------------------------------------------------- page states and stepper

    public function test_a_fresh_instance_offers_to_create_the_app(): void
    {
        Livewire::actingAs($this->admin())->test(Connect::class)
            ->assertSee('Create the GitHub App')
            ->assertSee('Create it for me')
            ->assertSee('I already have an app')
            ->assertSee('0 of 3')
            ->assertSee('Self-hosted runners')   // what GitHub will ask the admin to approve
            ->assertDontSee('Installations');
    }

    public function test_a_connected_app_waits_for_an_installation_and_polls(): void
    {
        $app = GithubApp::factory()->create(['slug' => 'jogger-acme']);

        Livewire::actingAs($this->admin())->test(Connect::class)
            ->assertSee('1 of 3')
            ->assertSee('jogger-acme')
            ->assertSee('Waiting for GitHub')
            ->assertSeeHtml('href="https://github.com/apps/jogger-acme/installations/new"')
            ->assertSeeHtml('wire:poll.5s')
            ->assertSee(rtrim(config('app.url'), '/').'/webhooks/github')
            ->assertDontSee('Create it for me');

        $this->assertNotNull($app);
    }

    public function test_installations_and_repositories_advance_the_stepper_and_stop_polling(): void
    {
        $app = GithubApp::factory()->create();
        $installation = Installation::factory()->for($app, 'app')->create(['account_login' => 'octo-org']);

        Livewire::actingAs($this->admin())->test(Connect::class)
            ->assertSee('2 of 3')->assertSee('octo-org')->assertSee('Active')->assertSee('Choose repositories when installing')
            ->assertDontSeeHtml('wire:poll.5s');

        Repository::factory()->count(3)->for($installation)->create();

        Livewire::actingAs($this->admin())->test(Connect::class)->assertSee('3 of 3')->assertSee('3 repositories');
    }

    // ---------------------------------------------------------------- reachability banner

    #[DataProvider('urls')]
    public function test_the_reachability_check(string $url, bool $https, bool $public): void
    {
        $check = WebhookReachability::check($url);

        $this->assertSame($https, $check->https, 'https');
        $this->assertSame($public, $check->public, 'public');
        $this->assertSame($https && $public, $check->ok());
        $this->assertSame($check->ok(), $check->warning() === null);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool}>
     */
    public static function urls(): array
    {
        return [
            'public https' => ['https://jogger.example.com', true, true],
            'public https with port and path' => ['https://ci.example.org:8443/', true, true],
            'public http' => ['http://jogger.example.com', false, true],
            'localhost' => ['http://localhost:8000', false, false],
            'localhost https' => ['https://localhost', true, false],
            'loopback ip' => ['http://127.0.0.1:8123', false, false],
            'ipv6 loopback' => ['http://[::1]:8000', false, false],
            'private 192.168' => ['https://192.168.1.20', true, false],
            'private 10.x' => ['https://10.0.0.5', true, false],
            'private 172.16' => ['https://172.16.4.2', true, false],
            '.test domain' => ['https://jogger.test', true, false],
            '.local domain' => ['https://nas.local', true, false],
            'single-label host' => ['https://jogger', true, false],
            'public ip' => ['https://203.0.113.7', true, true],
        ];
    }

    public function test_the_banner_warns_for_local_and_non_https_urls_and_hides_for_public_https(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        Livewire::actingAs($this->admin())->test(Connect::class)
            ->assertSeeHtml('data-testid="reachability-warning"')->assertSee('local address');

        config(['app.url' => 'http://jogger.example.com']);
        Livewire::actingAs($this->admin())->test(Connect::class)
            ->assertSeeHtml('data-testid="reachability-warning"')->assertSee('not HTTPS');

        config(['app.url' => 'https://jogger.example.com']);
        Livewire::actingAs($this->admin())->test(Connect::class)->assertDontSeeHtml('data-testid="reachability-warning"');
    }

    // ---------------------------------------------------------------- manual credentials

    /**
     * @return array<string, string>
     */
    private function validInput(): array
    {
        return ['appId' => '837201', 'clientId' => 'Iv1.8a61f9b3', 'clientSecret' => 'client-secret-value', 'webhookSecret' => 'webhook-secret-value', 'privateKey' => self::$pem];
    }

    private function fill(Testable $component, array $input): Testable
    {
        foreach ($input as $key => $value) {
            $component->set($key, $value);
        }

        return $component;
    }

    public function test_manual_credentials_are_verified_with_github_before_saving(): void
    {
        Http::fake(['api.github.com/app' => Http::response(['id' => 837201, 'slug' => 'my-own-app', 'name' => 'My own app'], 200)]);
        $admin = $this->admin();

        $component = $this->fill(Livewire::actingAs($admin)->test(Connect::class), $this->validInput())->call('connectManually');

        $component->assertHasNoErrors()->assertSet('clientSecret', '')->assertSet('privateKey', '')->assertSee('my-own-app');
        $app = GithubApp::firstOrFail();
        $this->assertSame(837201, $app->app_id);
        $this->assertSame('my-own-app', $app->slug);
        $this->assertSame('client-secret-value', $app->client_secret);
        $this->assertSame('webhook-secret-value', $app->webhook_secret);

        Http::assertSent(function (Request $r) {
            return $r->method() === 'GET' && $r->url() === 'https://api.github.com/app'
                && str_starts_with($r->header('Authorization')[0], 'Bearer ey'); // a signed app JWT
        });
        $log = AuditLog::where('action', 'github.app_connected')->firstOrFail();
        $this->assertSame('manual', $log->metadata['method']);
        $this->assertSame($admin->id, $log->user_id);
    }

    public function test_a_key_pasted_with_escaped_newlines_and_crlf_is_accepted(): void
    {
        Http::fake(['api.github.com/app' => Http::response(['id' => 837201, 'slug' => 'x'], 200)]);

        foreach ([str_replace("\n", '\\n', self::$pem), str_replace("\n", "\r\n", self::$pem), "  \n".self::$pem."\n  "] as $mangled) {
            GithubApp::query()->delete();
            $this->fill(Livewire::actingAs($this->admin())->test(Connect::class), ['privateKey' => $mangled] + $this->validInput())
                ->call('connectManually')->assertHasNoErrors();
            $this->assertSame(1, GithubApp::count());
        }
    }

    public function test_an_invalid_pem_is_refused_without_calling_github(): void
    {
        Http::fake();

        $this->fill(Livewire::actingAs($this->admin())->test(Connect::class), ['privateKey' => 'not a key'] + $this->validInput())
            ->call('connectManually')->assertHasErrors('manual');

        Http::assertNothingSent();
        $this->assertSame(0, GithubApp::count());
    }

    public function test_credentials_github_rejects_are_not_saved(): void
    {
        Http::fake(['api.github.com/app' => Http::response(['message' => 'A JSON web token could not be decoded'], 401)]);

        $component = $this->fill(Livewire::actingAs($this->admin())->test(Connect::class), $this->validInput())->call('connectManually');

        $component->assertHasErrors('manual')->assertSee('GitHub rejected these credentials');
        $this->assertSame(0, GithubApp::count());
        $this->assertSame(0, AuditLog::where('action', 'github.app_connected')->count());
    }

    public function test_a_key_for_a_different_app_is_detected(): void
    {
        Http::fake(['api.github.com/app' => Http::response(['id' => 999, 'slug' => 'someone-else'], 200)]);

        $this->fill(Livewire::actingAs($this->admin())->test(Connect::class), $this->validInput())
            ->call('connectManually')->assertSee('different app');

        $this->assertSame(0, GithubApp::count());
    }

    public function test_github_being_unreachable_during_verification_saves_nothing(): void
    {
        Http::fake(['api.github.com/app' => fn () => throw new ConnectionException('timeout')]);

        $this->fill(Livewire::actingAs($this->admin())->test(Connect::class), $this->validInput())
            ->call('connectManually')->assertSee('Could not reach GitHub');

        $this->assertSame(0, GithubApp::count());
    }

    public function test_manual_form_validation(): void
    {
        Http::fake();

        Livewire::actingAs($this->admin())->test(Connect::class)->call('connectManually')
            ->assertHasErrors(['appId', 'clientId', 'clientSecret', 'webhookSecret', 'privateKey']);
        $this->fill(Livewire::actingAs($this->admin())->test(Connect::class), ['appId' => 'abc'] + $this->validInput())
            ->call('connectManually')->assertHasErrors('appId');

        Http::assertNothingSent();
    }

    public function test_connecting_a_second_app_is_refused(): void
    {
        GithubApp::factory()->create();
        Http::fake();

        $this->fill(Livewire::actingAs($this->admin())->test(Connect::class), $this->validInput())
            ->call('connectManually')->assertHasErrors('manual');

        $this->assertSame(1, GithubApp::count());
        Http::assertNothingSent();
    }

    public function test_the_manual_action_is_admin_only_even_when_called_directly(): void
    {
        Http::fake();

        $this->fill(Livewire::actingAs(User::factory()->create())->test(Connect::class), $this->validInput())
            ->call('connectManually')->assertForbidden();

        $this->assertSame(0, GithubApp::count());
        Http::assertNothingSent();
    }
}
