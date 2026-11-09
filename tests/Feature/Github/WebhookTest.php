<?php

declare(strict_types=1);

namespace Tests\Feature\Github;

use App\Domain\Github\Actions\ProcessWebhookDelivery;
use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Github\Webhooks\WebhookHandlers;
use App\Http\Middleware\EnsureSetupComplete;
use App\Http\Middleware\VerifyGithubSignature;
use App\Models\User;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CountingHandler;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const DELIVERY = '72d3162e-cc78-11e3-81ab-4c9367dc0958';

    private GithubApp $githubApp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->githubApp = GithubApp::factory()->create(['app_id' => 837201, 'webhook_secret' => 'It\'s a Secret to Everybody']);
        CountingHandler::reset();
        app(WebhookHandlers::class)->register('workflow_job', CountingHandler::class);
    }

    /**
     * @param  array<string, mixed>|string  $payload
     * @param  array<string, string>  $headers  overrides, e.g. ['HTTP_X_GITHUB_DELIVERY' => null] to drop one
     */
    private function deliver(array|string $payload, array $headers = [], ?string $secret = null): TestResponse
    {
        $body = is_string($payload) ? $payload : json_encode($payload);
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'workflow_job',
            'HTTP_X_GITHUB_DELIVERY' => self::DELIVERY,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret ?? $this->githubApp->webhook_secret),
            'HTTP_X_GITHUB_HOOK_INSTALLATION_TARGET_ID' => '837201',
            'HTTP_X_GITHUB_HOOK_INSTALLATION_TARGET_TYPE' => 'integration',
            'HTTP_USER_AGENT' => 'GitHub-Hookshot/044aadd',
        ];

        foreach ($headers as $key => $value) {
            if ($value === null) {
                unset($server[$key]);
            } else {
                $server[$key] = $value;
            }
        }

        return $this->call('POST', '/webhooks/github', [], [], [], $server, $body);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name = 'workflow_job.queued'): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/github/{$name}.json")), true);
    }

    // ---------------------------------------------------------------- signature

    public function test_githubs_documented_test_vector_is_accepted(): void
    {
        // From docs.github.com "Validating webhook deliveries": secret "It's a Secret to Everybody", payload "Hello, World!".
        $this->assertSame('757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17', hash_hmac('sha256', 'Hello, World!', "It's a Secret to Everybody"));

        // The signature is valid, so the request gets past authentication; the body is not JSON, hence 400 (not 401).
        $this->deliver('Hello, World!', ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=757107ea0eb2509fc211221cce984b8a37570b6d7586c22c46f4379c8b043e17'])
            ->assertStatus(400);
    }

    public function test_a_valid_signed_delivery_is_stored_and_queued_without_running_handlers_inline(): void
    {
        Queue::fake();

        $response = $this->deliver($this->fixture());

        $response->assertOk()->assertJsonPath('status', 'accepted');
        $delivery = WebhookDelivery::firstOrFail();
        $this->assertSame(self::DELIVERY, $delivery->delivery_id);
        $this->assertSame('workflow_job', $delivery->event);
        $this->assertSame('queued', $delivery->action);
        $this->assertSame($this->githubApp->id, $delivery->github_app_id);
        $this->assertSame(DeliveryStatus::Received, $delivery->status);
        $this->assertSame(399444496, $delivery->payload['workflow_job']['id']);
        $this->assertSame($delivery->id, $response->json('delivery'));

        Queue::assertPushed(ProcessWebhookDelivery::class, 1);
        Queue::assertPushed(ProcessWebhookDelivery::class, fn (ProcessWebhookDelivery $job) => $job->deliveryId === $delivery->id);
        $this->assertSame(0, CountingHandler::$calls, 'handlers must not run inside the request');
    }

    public function test_the_endpoint_answers_in_well_under_half_a_second(): void
    {
        Queue::fake();
        $payload = $this->fixture();

        $start = hrtime(true);
        $this->deliver($payload)->assertOk();
        $milliseconds = (hrtime(true) - $start) / 1e6;

        $this->assertLessThan(500, $milliseconds);
    }

    /**
     * @return array<string, array{0: array<string, string|null>, 1: ?string}>
     */
    public static function badSignatures(): array
    {
        return [
            'wrong secret' => [[], 'wrong-secret'],
            'missing header' => [['HTTP_X_HUB_SIGNATURE_256' => null], null],
            'empty header' => [['HTTP_X_HUB_SIGNATURE_256' => ''], null],
            'no sha256 prefix' => [['HTTP_X_HUB_SIGNATURE_256' => 'd57c68ca6f92289e6987922ff26938930f6e66a2d161ef06abdf1859230aa23c'], null],
            'sha1 header only' => [['HTTP_X_HUB_SIGNATURE_256' => null, 'HTTP_X_HUB_SIGNATURE' => 'sha1=7d38cdd689735b008b3c702edd92eea23791c5f6'], null],
            'unknown app id' => [['HTTP_X_GITHUB_HOOK_INSTALLATION_TARGET_ID' => '999'], null],
            'non-numeric app id' => [['HTTP_X_GITHUB_HOOK_INSTALLATION_TARGET_ID' => '837201 OR 1=1'], null],
        ];
    }

    /**
     * @param  array<string, string|null>  $headers
     */
    #[DataProvider('badSignatures')]
    public function test_a_bad_or_missing_signature_is_a_401_and_nothing_is_stored_or_queued(array $headers, ?string $secret): void
    {
        Queue::fake();

        $this->deliver($this->fixture(), $headers, $secret)
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'UNAUTHENTICATED')
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);

        $this->assertSame(0, WebhookDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_a_body_changed_after_signing_is_rejected(): void
    {
        Queue::fake();
        $original = json_encode($this->fixture());
        $signature = 'sha256='.hash_hmac('sha256', $original, $this->githubApp->webhook_secret);

        $tampered = str_replace('"queued"', '"completed"', $original);
        $this->deliver($tampered, ['HTTP_X_HUB_SIGNATURE_256' => $signature])->assertUnauthorized();

        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_signature_comparison_is_constant_time(): void
    {
        $source = (string) file_get_contents((new \ReflectionClass(VerifyGithubSignature::class))->getFileName());

        $this->assertStringContainsString('hash_equals(', $source);
    }

    public function test_when_the_target_header_is_missing_the_only_connected_app_is_used(): void
    {
        Queue::fake();

        $this->deliver($this->fixture(), ['HTTP_X_GITHUB_HOOK_INSTALLATION_TARGET_ID' => null])->assertOk();
        $this->assertSame(1, WebhookDelivery::count());
    }

    public function test_without_the_target_header_and_several_apps_the_request_cannot_be_attributed(): void
    {
        GithubApp::factory()->create();

        $this->deliver($this->fixture(), ['HTTP_X_GITHUB_HOOK_INSTALLATION_TARGET_ID' => null])->assertUnauthorized();
    }

    // ---------------------------------------------------------------- request validation

    public function test_a_malformed_or_missing_delivery_id_is_a_400(): void
    {
        foreach ([null, 'not-a-uuid', ''] as $bad) {
            $this->deliver($this->fixture(), ['HTTP_X_GITHUB_DELIVERY' => $bad])->assertStatus(400);
        }
        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_a_missing_event_header_or_non_json_body_is_a_400_even_with_a_valid_signature(): void
    {
        $this->deliver($this->fixture(), ['HTTP_X_GITHUB_EVENT' => null])->assertStatus(400);
        $this->deliver('this is not json')->assertStatus(400);
        $this->deliver('"just a string"')->assertStatus(400);
        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_form_encoded_deliveries_are_decoded_from_the_payload_field(): void
    {
        Queue::fake();
        $body = 'payload='.urlencode(json_encode($this->fixture()));

        $this->deliver($body, ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'])->assertOk();

        $this->assertSame('queued', WebhookDelivery::firstOrFail()->action);
    }

    public function test_an_overlong_action_is_truncated_and_a_missing_one_is_null(): void
    {
        Queue::fake();

        $this->deliver(['action' => str_repeat('x', 100), 'zen' => 'Keep it logically awesome.'], ['HTTP_X_GITHUB_DELIVERY' => 'aaaaaaaa-cc78-11e3-81ab-4c9367dc0958'])->assertOk();
        $this->deliver(['zen' => 'no action here'], ['HTTP_X_GITHUB_DELIVERY' => 'bbbbbbbb-cc78-11e3-81ab-4c9367dc0958'])->assertOk();

        $this->assertSame(40, strlen((string) WebhookDelivery::where('delivery_id', 'aaaaaaaa-cc78-11e3-81ab-4c9367dc0958')->value('action')));
        $this->assertNull(WebhookDelivery::where('delivery_id', 'bbbbbbbb-cc78-11e3-81ab-4c9367dc0958')->value('action'));
    }

    // ---------------------------------------------------------------- size limit

    public function test_a_declared_payload_over_25_mb_is_rejected_with_413_before_anything_else(): void
    {
        Queue::fake();

        // Even with a bad signature: the size check comes first so we never hash (or store) huge bodies.
        $this->deliver($this->fixture(), ['HTTP_CONTENT_LENGTH' => (string) (25 * 1024 * 1024 + 1), 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad'])
            ->assertStatus(413)
            ->assertJsonPath('error.code', 'PAYLOAD_TOO_LARGE');

        $this->assertSame(0, WebhookDelivery::count());
        Queue::assertNothingPushed();
    }

    public function test_an_actual_body_over_25_mb_is_rejected_even_without_a_content_length_header(): void
    {
        $body = str_repeat('a', VerifyGithubSignature::MAX_BYTES + 1);

        $this->deliver($body)->assertStatus(413);
        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_a_body_exactly_at_the_limit_gets_past_the_size_check(): void
    {
        // 25 MB of valid JSON-ish padding with a correct signature: not 413 (it fails later, as malformed/ignored content).
        $body = str_repeat(' ', VerifyGithubSignature::MAX_BYTES - 2).'{}';

        $this->assertNotSame(413, $this->deliver($body)->getStatusCode());
    }

    // ---------------------------------------------------------------- duplicates and redelivery

    public function test_the_same_delivery_twice_gets_200_both_times_and_is_processed_once(): void
    {
        // Queue left on the sync driver: the handler really runs when the delivery is accepted.
        $first = $this->deliver($this->fixture());
        $second = $this->deliver($this->fixture());

        $first->assertOk()->assertJsonPath('status', 'accepted');
        $second->assertOk()->assertJsonPath('status', 'duplicate');
        $this->assertSame(1, WebhookDelivery::count());
        $this->assertSame(1, CountingHandler::$calls);
        $this->assertSame(DeliveryStatus::Processed, WebhookDelivery::firstOrFail()->status);
    }

    public function test_different_deliveries_are_all_processed(): void
    {
        foreach (['aaaaaaaa', 'bbbbbbbb', 'cccccccc'] as $prefix) {
            $this->deliver($this->fixture(), ['HTTP_X_GITHUB_DELIVERY' => "{$prefix}-cc78-11e3-81ab-4c9367dc0958"])->assertOk();
        }

        $this->assertSame(3, CountingHandler::$calls);
    }

    public function test_delivery_ids_are_case_insensitive(): void
    {
        Queue::fake();

        $this->deliver($this->fixture(), ['HTTP_X_GITHUB_DELIVERY' => strtoupper(self::DELIVERY)])->assertJsonPath('status', 'accepted');
        $this->deliver($this->fixture(), ['HTTP_X_GITHUB_DELIVERY' => self::DELIVERY])->assertJsonPath('status', 'duplicate');

        $this->assertSame(1, WebhookDelivery::count());
    }

    public function test_a_redelivery_of_a_finished_delivery_is_acknowledged_but_not_run_again(): void
    {
        $this->deliver($this->fixture())->assertOk();
        Queue::fake();

        $this->deliver($this->fixture())->assertOk()->assertJsonPath('status', 'duplicate');

        Queue::assertNothingPushed();
        $this->assertSame(1, CountingHandler::$calls);
    }

    public function test_github_redelivering_a_failed_delivery_gives_it_another_go(): void
    {
        $delivery = WebhookDelivery::factory()->create(['delivery_id' => self::DELIVERY, 'event' => 'workflow_job', 'payload' => $this->fixture(), 'status' => DeliveryStatus::Failed, 'error' => 'boom']);
        Queue::fake();

        $this->deliver($this->fixture())->assertOk()->assertJsonPath('status', 'duplicate');

        Queue::assertPushed(ProcessWebhookDelivery::class, fn (ProcessWebhookDelivery $j) => $j->deliveryId === $delivery->id);
        $this->assertSame(1, WebhookDelivery::count());
    }

    public function test_a_redelivery_of_one_that_was_stored_but_never_picked_up_is_queued_again(): void
    {
        $delivery = WebhookDelivery::factory()->create(['delivery_id' => self::DELIVERY, 'payload' => $this->fixture(), 'status' => DeliveryStatus::Received]);
        Queue::fake();

        $this->deliver($this->fixture())->assertOk();

        Queue::assertPushed(ProcessWebhookDelivery::class, fn (ProcessWebhookDelivery $j) => $j->deliveryId === $delivery->id);
    }

    public function test_a_redelivery_while_a_worker_is_processing_it_does_nothing(): void
    {
        WebhookDelivery::factory()->create(['delivery_id' => self::DELIVERY, 'status' => DeliveryStatus::Processing]);
        Queue::fake();

        $this->deliver($this->fixture())->assertOk()->assertJsonPath('status', 'duplicate');

        Queue::assertNothingPushed();
    }

    // ---------------------------------------------------------------- routing

    public function test_the_route_has_no_session_cookie_or_csrf_machinery(): void
    {
        $route = Route::getRoutes()->getByName('webhooks.github');
        $middleware = app('router')->gatherRouteMiddleware($route);

        foreach ([StartSession::class, ValidateCsrfToken::class, EncryptCookies::class, EnsureSetupComplete::class] as $forbidden) {
            $this->assertNotContains($forbidden, $middleware);
        }

        Queue::fake();
        $this->deliver($this->fixture())->assertOk()->assertHeaderMissing('Set-Cookie');
    }

    public function test_webhooks_work_on_a_fresh_install_with_no_users(): void
    {
        Queue::fake();
        $this->assertSame(0, User::count());

        $this->deliver($this->fixture())->assertOk(); // not redirected to /setup
    }

    public function test_only_post_is_allowed(): void
    {
        $this->get('/webhooks/github')->assertStatus(405);
    }

    public function test_responses_carry_a_request_id_for_correlation(): void
    {
        Queue::fake();

        $this->deliver($this->fixture())->assertHeader('X-Request-Id');
    }
}
