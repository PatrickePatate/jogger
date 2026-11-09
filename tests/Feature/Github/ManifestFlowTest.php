<?php

declare(strict_types=1);

namespace Tests\Feature\Github;

use App\Domain\Access\Models\AuditLog;
use App\Domain\Github\Models\GithubApp;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ManifestFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CODE = 'a180b1a3d263c81bc6441d7b990bae27d4c10679';

    private static ?string $pem = null;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        self::$pem ??= (string) GithubApp::factory()->withRealKey()->make()->private_key;
        $this->admin = User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function conversion(): array
    {
        $body = json_decode((string) file_get_contents(base_path('tests/Fixtures/github/app_manifest_conversion.response.json')), true);
        $body['pem'] = self::$pem;

        return $body;
    }

    /** Starts the flow like the browser does and returns the state GitHub would echo back. */
    private function startFlow(array $input = ['account_type' => 'personal']): string
    {
        $this->actingAs($this->admin)->post('/github/manifest/redirect', $input)->assertOk();

        return (string) session('github.manifest.state');
    }

    /**
     * @return array<string, mixed>
     */
    private function manifestFrom(string $html): array
    {
        preg_match('/name="manifest" value="([^"]*)"/', $html, $m);

        return json_decode(html_entity_decode($m[1]), true, flags: JSON_THROW_ON_ERROR);
    }

    // ---------------------------------------------------------------- step 1: redirect to GitHub

    public function test_the_redirect_page_posts_the_manifest_to_github_for_a_personal_account(): void
    {
        $response = $this->actingAs($this->admin)->post('/github/manifest/redirect', ['account_type' => 'personal']);

        $response->assertOk()->assertSee('id="manifest-form"', false);
        $state = (string) session('github.manifest.state');
        $this->assertSame(40, strlen($state));
        $response->assertSee('action="https://github.com/settings/apps/new?state='.$state.'"', false);
        $response->assertSee('method="POST"', false);
    }

    public function test_an_organization_account_uses_the_organization_url(): void
    {
        $state = $this->startFlow(['account_type' => 'organization', 'organization' => 'octo-org']);

        $this->actingAs($this->admin)->post('/github/manifest/redirect', ['account_type' => 'organization', 'organization' => 'octo-org'])
            ->assertSee('https://github.com/organizations/octo-org/settings/apps/new?state=', false);
        $this->assertNotSame($state, session('github.manifest.state')); // a fresh state every time
    }

    public function test_the_manifest_requests_exactly_what_jogger_needs(): void
    {
        config(['app.url' => 'https://jogger.acme.example']);

        $manifest = $this->manifestFrom($this->actingAs($this->admin)->post('/github/manifest/redirect', ['account_type' => 'personal'])->getContent());

        $this->assertSame('https://jogger.acme.example', $manifest['url']);
        $this->assertSame('https://jogger.acme.example/webhooks/github', $manifest['hook_attributes']['url']);
        $this->assertTrue($manifest['hook_attributes']['active']);
        $this->assertSame('https://jogger.acme.example/github/manifest/callback', $manifest['redirect_url']);
        $this->assertSame(['https://jogger.acme.example/auth/github/callback'], $manifest['callback_urls']);
        $this->assertSame('https://jogger.acme.example/github', $manifest['setup_url']);
        $this->assertFalse($manifest['public']);
        // installation / installation_repositories are delivered automatically and cannot be subscribed to.
        $this->assertSame(['workflow_job', 'repository'], $manifest['default_events']);
        $this->assertEquals([
            'actions' => 'read',
            'administration' => 'write',
            'metadata' => 'read',
            'organization_self_hosted_runners' => 'write',
        ], $manifest['default_permissions']);
        $this->assertSame('Jogger jogger', $manifest['name']);
    }

    public function test_the_app_name_is_customisable_and_capped_at_34_characters(): void
    {
        $named = $this->manifestFrom($this->actingAs($this->admin)->post('/github/manifest/redirect', ['account_type' => 'personal', 'app_name' => 'Acme CI'])->getContent());
        $this->assertSame('Acme CI', $named['name']);

        $this->actingAs($this->admin)->post('/github/manifest/redirect', ['account_type' => 'personal', 'app_name' => str_repeat('x', 35)])
            ->assertSessionHasErrors('app_name');
    }

    #[DataProvider('badRedirectInput')]
    public function test_the_redirect_validates_its_input(array $input, string $errorField): void
    {
        $this->actingAs($this->admin)->post('/github/manifest/redirect', $input)->assertSessionHasErrors($errorField);
        $this->assertNull(session('github.manifest'));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string}>
     */
    public static function badRedirectInput(): array
    {
        return [
            'unknown account type' => [['account_type' => 'enterprise'], 'account_type'],
            'missing account type' => [[], 'account_type'],
            'organization without a login' => [['account_type' => 'organization'], 'organization'],
            'login with a slash' => [['account_type' => 'organization', 'organization' => 'a/b'], 'organization'],
            'login starting with a dash' => [['account_type' => 'organization', 'organization' => '-evil'], 'organization'],
        ];
    }

    // ---------------------------------------------------------------- step 3: callback

    public function test_a_valid_code_stores_the_app_with_encrypted_secrets_and_is_audited(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->conversion(), 201)]);
        $state = $this->startFlow();

        $this->get('/github/manifest/callback?code='.self::CODE."&state={$state}")
            ->assertRedirect(route('github.connect'))
            ->assertSessionHas('status');

        $app = GithubApp::firstOrFail();
        $this->assertSame(837201, $app->app_id);
        $this->assertSame('jogger-acme', $app->slug);
        $this->assertSame('Iv1.8a61f9b3a7aba766', $app->client_id);
        $this->assertSame('1726be1638095a19edd134c77bde3aa2ece1e5d8', $app->client_secret);
        $this->assertSame('e340154128314309424b7c8e90325147d99fdafa', $app->webhook_secret);
        $this->assertSame(self::$pem, $app->private_key);
        $this->assertSame('https://api.github.com', $app->api_base_url);

        $raw = (array) DB::table('github_apps')->first();
        foreach (['client_secret', 'private_key', 'webhook_secret'] as $column) {
            $this->assertStringStartsWith('eyJ', (string) $raw[$column]); // encrypted envelope, not plaintext
        }

        $log = AuditLog::where('action', 'github.app_connected')->firstOrFail();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame($app->id, $log->target_id);
        $this->assertEquals(['app_id' => 837201, 'slug' => 'jogger-acme', 'method' => 'manifest'], $log->metadata);
        $this->assertStringNotContainsString('1726be16', json_encode($log->toArray())); // no secrets in the audit trail
    }

    public function test_the_code_exchange_is_unauthenticated_and_targets_the_documented_endpoint(): void
    {
        Http::fake(['api.github.com/*' => Http::response($this->conversion(), 201)]);
        $state = $this->startFlow();

        $this->get('/github/manifest/callback?code='.self::CODE."&state={$state}");

        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && $r->url() === 'https://api.github.com/app-manifests/'.self::CODE.'/conversions'
                && ! $r->hasHeader('Authorization')
                && $r->header('Accept')[0] === 'application/vnd.github+json';
        });
        Http::assertSentCount(1);
    }

    #[DataProvider('rejectedCodes')]
    public function test_an_invalid_or_expired_code_shows_an_error_and_stores_nothing(int $status): void
    {
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Not Found'], $status)]);
        $state = $this->startFlow();

        $this->get('/github/manifest/callback?code='.self::CODE."&state={$state}")
            ->assertRedirect(route('github.connect'))
            ->assertSessionHas('error');

        $this->assertStringContainsString('invalid or has expired', (string) session('error'));
        $this->assertSame(0, GithubApp::count());
        $this->assertSame(0, AuditLog::where('action', 'github.app_connected')->count());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function rejectedCodes(): array
    {
        return ['404 not found' => [404], '422 validation failed' => [422]];
    }

    public function test_the_error_is_displayed_on_the_connect_page(): void
    {
        Http::fake(['api.github.com/*' => Http::response([], 404)]);
        $state = $this->startFlow();

        $this->followingRedirects()->get('/github/manifest/callback?code='.self::CODE."&state={$state}")
            ->assertOk()->assertSee('invalid or has expired')->assertSee('Create the GitHub App');
    }

    #[DataProvider('badStates')]
    public function test_a_missing_wrong_expired_or_reused_state_is_refused_before_contacting_github(string $scenario): void
    {
        Http::fake();
        $state = $this->startFlow();
        $query = match ($scenario) {
            'wrong' => 'state=not-the-state',
            'missing' => '',
            'expired' => "state={$state}",
            'reused' => "state={$state}",
        };
        if ($scenario === 'expired') {
            $this->travel(56)->minutes();
        }
        if ($scenario === 'reused') {
            Http::fake(['api.github.com/*' => Http::response($this->conversion(), 201)]);
            $this->get('/github/manifest/callback?code='.self::CODE.'&'.$query); // first use consumes it
            GithubApp::query()->delete();
            Http::fake();
        }

        $this->get('/github/manifest/callback?code='.self::CODE.'&'.$query)->assertRedirect(route('github.connect'))->assertSessionHas('error');

        $this->assertStringContainsString('could not be verified', (string) session('error'));
        $this->assertSame(0, GithubApp::count());
        if ($scenario !== 'reused') {
            Http::assertNothingSent();
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function badStates(): array
    {
        return ['wrong state' => ['wrong'], 'missing state' => ['missing'], 'expired state' => ['expired'], 'reused state' => ['reused']];
    }

    public function test_a_callback_without_any_flow_in_progress_is_refused(): void
    {
        Http::fake();

        $this->actingAs($this->admin)->get('/github/manifest/callback?code='.self::CODE.'&state=guess')->assertSessionHas('error');

        Http::assertNothingSent();
    }

    public function test_a_missing_code_explains_itself(): void
    {
        Http::fake();
        $state = $this->startFlow();

        $this->get("/github/manifest/callback?state={$state}")->assertSessionHas('error');

        $this->assertStringContainsString('did not send a setup code', (string) session('error'));
        Http::assertNothingSent();
    }

    public function test_codes_that_could_alter_the_url_path_are_refused_without_a_request(): void
    {
        Http::fake();

        foreach (['../../user', 'abc', 'code with spaces', str_repeat('a', 200)] as $bad) {
            $state = $this->startFlow();
            $this->get('/github/manifest/callback?code='.urlencode($bad)."&state={$state}")->assertSessionHas('error');
        }

        Http::assertNothingSent();
        $this->assertSame(0, GithubApp::count());
    }

    public function test_an_incomplete_github_response_saves_nothing(): void
    {
        $body = $this->conversion();
        unset($body['pem']);
        Http::fake(['api.github.com/*' => Http::response($body, 201)]);
        $state = $this->startFlow();

        $this->get('/github/manifest/callback?code='.self::CODE."&state={$state}")->assertSessionHas('error');

        $this->assertStringContainsString('missing “pem”', (string) session('error'));
        $this->assertSame(0, GithubApp::count());
    }

    public function test_an_unreachable_github_is_reported_without_saving(): void
    {
        Http::fake(['api.github.com/*' => fn () => throw new ConnectionException('cURL error 6')]);
        $state = $this->startFlow();

        $this->get('/github/manifest/callback?code='.self::CODE."&state={$state}")->assertSessionHas('error');

        $this->assertStringContainsString('Could not reach GitHub', (string) session('error'));
        $this->assertSame(0, GithubApp::count());
    }

    public function test_only_one_app_can_be_connected(): void
    {
        GithubApp::factory()->create();
        Http::fake();
        $state = $this->startFlow();

        $this->get('/github/manifest/callback?code='.self::CODE."&state={$state}")->assertSessionHas('error');

        $this->assertStringContainsString('already connected', (string) session('error'));
        $this->assertSame(1, GithubApp::count());
        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------- access

    public function test_only_administrators_can_use_the_flow(): void
    {
        $member = User::factory()->create();
        Http::fake();

        $this->actingAs($member)->get('/github')->assertForbidden();
        $this->actingAs($member)->post('/github/manifest/redirect', ['account_type' => 'personal'])->assertForbidden();
        $this->actingAs($member)->get('/github/manifest/callback?code='.self::CODE.'&state=x')->assertForbidden();
        Http::assertNothingSent();

        auth()->logout();
        $this->get('/github')->assertRedirect(route('login'));
    }

    public function test_administrators_see_a_github_link_in_the_navigation(): void
    {
        $this->actingAs($this->admin)->get('/')->assertSee('href="'.route('github.connect').'"', false);
        $this->actingAs(User::factory()->create())->get('/')->assertDontSee('href="'.route('github.connect').'"', false);
    }
}
