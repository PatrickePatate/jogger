<?php

declare(strict_types=1);

namespace Tests\Feature\Github;

use App\Domain\Github\Actions\ProcessWebhookDelivery;
use App\Domain\Github\Data\RepositoryData;
use App\Domain\Github\Enums\AccountType;
use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Enums\InstallationStatus;
use App\Domain\Github\Enums\RepositorySelection;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Github\Webhooks\WebhookHandlers;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubRateLimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InstallationSyncTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALLATION = 4730226;

    private GithubApp $githubApp;

    private FakeGithubGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->githubApp = GithubApp::factory()->create(['app_id' => 837201]);
        $this->gateway = FakeGithubGateway::install();
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name, ?callable $mutate = null): array
    {
        $payload = json_decode((string) file_get_contents(base_path("tests/Fixtures/github/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);

        return $mutate ? $mutate($payload) : $payload;
    }

    /** Runs a payload through the real job (claim → handler → status), exactly like a worker. */
    private function process(string $event, array $payload, ?WebhookDelivery $delivery = null): WebhookDelivery
    {
        $delivery ??= WebhookDelivery::factory()->create([
            'github_app_id' => $this->githubApp->id,
            'event' => $event,
            'action' => $payload['action'] ?? null,
            'payload' => $payload,
            'status' => DeliveryStatus::Received,
        ]);
        $delivery->forceFill(['status' => DeliveryStatus::Received])->save();

        (new ProcessWebhookDelivery($delivery->id))->withFakeQueueInteractions()->handle(app(WebhookHandlers::class));

        return $delivery->refresh();
    }

    private function installation(): Installation
    {
        return Installation::where('github_installation_id', self::INSTALLATION)->firstOrFail();
    }

    /**
     * @return array<int, string>
     */
    private function repoNames(): array
    {
        return Repository::orderBy('full_name')->pluck('full_name')->all();
    }

    private function existingInstallation(RepositorySelection $selection = RepositorySelection::Selected, InstallationStatus $status = InstallationStatus::Active): Installation
    {
        return Installation::factory()->for($this->githubApp, 'app')->create([
            'github_installation_id' => self::INSTALLATION, 'account_login' => 'octo-org', 'repository_selection' => $selection, 'status' => $status,
        ]);
    }

    // ---------------------------------------------------------------- installation.created

    public function test_created_stores_the_installation_and_its_repositories(): void
    {
        $delivery = $this->process('installation', $this->fixture('installation.created'));

        $this->assertSame(DeliveryStatus::Processed, $delivery->status);
        $installation = $this->installation();
        $this->assertSame('octo-org', $installation->account_login);
        $this->assertSame(AccountType::Organization, $installation->account_type);
        $this->assertSame(InstallationStatus::Active, $installation->status);
        $this->assertSame(RepositorySelection::Selected, $installation->repository_selection);
        $this->assertTrue($installation->app->is($this->githubApp));
        $this->assertSame(['octo-org/example-workflow', 'octo-org/public-docs'], $this->repoNames());
        $this->assertTrue(Repository::where('github_repo_id', 186853002)->firstOrFail()->private);
        $this->assertFalse(Repository::where('github_repo_id', 186853003)->firstOrFail()->private);
        $this->gateway->assertNotCalled('listInstallationRepositories'); // "selected": the payload is complete
        $this->assertNull($installation->synced_at);
    }

    public function test_created_for_all_repositories_asks_github_for_the_authoritative_list(): void
    {
        $this->gateway->repositories = [
            new RepositoryData(186853002, 'octo-org/example-workflow', true),
            new RepositoryData(186853003, 'octo-org/public-docs', false),
            new RepositoryData(186853010, 'octo-org/not-in-the-payload', true),
        ];

        $this->process('installation', $this->fixture('installation.created', function (array $p) {
            $p['installation']['repository_selection'] = 'all';

            return $p;
        }));

        $this->gateway->assertCalled('listInstallationRepositories');
        $this->assertContains('octo-org/not-in-the-payload', $this->repoNames());
        $this->assertCount(3, $this->repoNames());
        $this->assertNotNull($this->installation()->synced_at);
        $this->assertSame(RepositorySelection::All, $this->installation()->repository_selection);
    }

    public function test_a_failed_sync_does_not_fail_the_webhook_and_keeps_the_payload_data(): void
    {
        Log::spy();
        $this->gateway->failNextWith(new GithubApiError('GitHub is unavailable', 503));

        $delivery = $this->process('installation', $this->fixture('installation.created', function (array $p) {
            $p['installation']['repository_selection'] = 'all';

            return $p;
        }));

        $this->assertSame(DeliveryStatus::Processed, $delivery->status);
        $this->assertCount(2, $this->repoNames()); // from the payload
        $this->assertNull($this->installation()->synced_at);
        Log::shouldHaveReceived('warning')->with('github.installation_sync_failed', \Mockery::type('array'))->once();
    }

    public function test_a_rate_limited_sync_sends_the_delivery_back_to_the_queue_with_its_data_already_stored(): void
    {
        $this->gateway->failNextWith(new GithubRateLimited('slow', 75));
        $payload = $this->fixture('installation.created', function (array $p) {
            $p['installation']['repository_selection'] = 'all';

            return $p;
        });
        $delivery = WebhookDelivery::factory()->create(['github_app_id' => $this->githubApp->id, 'event' => 'installation', 'payload' => $payload, 'status' => DeliveryStatus::Received]);

        $job = (new ProcessWebhookDelivery($delivery->id))->withFakeQueueInteractions();
        $job->handle(app(WebhookHandlers::class));

        $job->assertReleased(delay: 75);
        $this->assertSame(DeliveryStatus::Received, $delivery->fresh()->status);
        $this->assertCount(2, $this->repoNames());

        // The retry succeeds and ends in the same state without duplicates.
        $job2 = (new ProcessWebhookDelivery($delivery->id))->withFakeQueueInteractions();
        $job2->handle(app(WebhookHandlers::class));
        $this->assertSame(DeliveryStatus::Processed, $delivery->fresh()->status);
        $this->assertSame(1, Installation::count());
    }

    // ---------------------------------------------------------------- lifecycle

    public function test_deleted_marks_the_installation_deleted_and_removes_its_repositories(): void
    {
        $this->process('installation', $this->fixture('installation.created'));

        $this->process('installation', $this->fixture('installation.deleted'));

        $this->assertSame(InstallationStatus::Deleted, $this->installation()->status);
        $this->assertSame([], $this->repoNames());
        $this->assertSame(1, Installation::count()); // history is kept
    }

    public function test_deleting_an_installation_we_never_knew_creates_nothing(): void
    {
        $delivery = $this->process('installation', $this->fixture('installation.deleted'));

        $this->assertSame(DeliveryStatus::Processed, $delivery->status);
        $this->assertSame(0, Installation::count());
    }

    public function test_suspend_and_unsuspend_toggle_the_status(): void
    {
        // What GitHub reports once the installation is active again (unsuspend triggers a sync).
        $this->gateway->repositories = [new RepositoryData(186853002, 'octo-org/example-workflow', true), new RepositoryData(186853003, 'octo-org/public-docs', false)];
        $this->process('installation', $this->fixture('installation.created'));
        $this->assertSame(InstallationStatus::Active, $this->installation()->status);

        $this->process('installation', $this->fixture('installation.suspend'));
        $this->assertSame(InstallationStatus::Suspended, $this->installation()->status);

        $this->process('installation', $this->fixture('installation.unsuspend'));
        $this->assertSame(InstallationStatus::Active, $this->installation()->status);
        $this->assertCount(2, $this->repoNames()); // suspension never removes repositories
    }

    public function test_unsuspend_refreshes_the_repository_list_from_github(): void
    {
        $this->existingInstallation(status: InstallationStatus::Suspended);
        $this->gateway->repositories = [new RepositoryData(186853002, 'octo-org/example-workflow', true)];

        $this->process('installation', $this->fixture('installation.unsuspend'));

        $this->gateway->assertCalled('listInstallationRepositories');
        $this->assertSame(['octo-org/example-workflow'], $this->repoNames());
    }

    public function test_new_permissions_accepted_keeps_the_installation_active(): void
    {
        $this->process('installation', $this->fixture('installation.created'));

        $this->process('installation', $this->fixture('installation.new_permissions_accepted'));

        $this->assertSame(InstallationStatus::Active, $this->installation()->status);
        $this->assertSame(1, Installation::count());
    }

    public function test_an_unknown_action_still_ends_in_the_state_the_payload_describes(): void
    {
        $suspended = $this->process('installation', $this->fixture('installation.suspend', function (array $p) {
            $p['action'] = 'some_future_action';

            return $p;
        }));

        $this->assertSame(DeliveryStatus::Processed, $suspended->status);
        $this->assertSame(InstallationStatus::Suspended, $this->installation()->status); // suspended_at is set
    }

    public function test_user_account_installations_are_recorded_as_user(): void
    {
        $this->process('installation', $this->fixture('installation.created', function (array $p) {
            $p['installation']['account']['type'] = 'User';

            return $p;
        }));

        $this->assertSame(AccountType::User, $this->installation()->account_type);
    }

    // ---------------------------------------------------------------- installation_repositories

    public function test_added_and_removed_repositories_are_applied_in_one_run(): void
    {
        $this->process('installation', $this->fixture('installation.created'));

        $this->process('installation_repositories', $this->fixture('installation_repositories.added'));
        $this->assertSame(['octo-org/example-workflow', 'octo-org/new-service', 'octo-org/open-docs', 'octo-org/public-docs'], $this->repoNames());

        $this->process('installation_repositories', $this->fixture('installation_repositories.removed'));
        $this->assertSame(['octo-org/example-workflow', 'octo-org/new-service', 'octo-org/open-docs'], $this->repoNames());
        $this->assertFalse(Repository::where('full_name', 'octo-org/open-docs')->firstOrFail()->private);
    }

    public function test_the_new_repository_selection_is_stored(): void
    {
        $this->existingInstallation(RepositorySelection::Selected);

        $this->process('installation_repositories', $this->fixture('installation_repositories.added', function (array $p) {
            $p['repository_selection'] = 'all';

            return $p;
        }));

        $this->assertSame(RepositorySelection::All, $this->installation()->repository_selection);
    }

    public function test_repository_changes_never_alter_the_installation_status(): void
    {
        $this->existingInstallation(status: InstallationStatus::Suspended);

        $this->process('installation_repositories', $this->fixture('installation_repositories.added'));

        $this->assertSame(InstallationStatus::Suspended, $this->installation()->status);
    }

    public function test_event_order_does_not_matter(): void
    {
        // installation_repositories arrives before (or without) the `created` event.
        $first = $this->process('installation_repositories', $this->fixture('installation_repositories.added'));
        $this->assertSame(DeliveryStatus::Processed, $first->status);
        $this->assertSame(1, Installation::count());
        $this->assertSame('octo-org', $this->installation()->account_login);

        $this->process('installation', $this->fixture('installation.created'));

        $this->assertSame(1, Installation::count());
        $this->assertContains('octo-org/new-service', $this->repoNames());
        $this->assertContains('octo-org/example-workflow', $this->repoNames());
    }

    public function test_removing_a_repository_we_do_not_have_is_harmless(): void
    {
        $this->existingInstallation();

        $delivery = $this->process('installation_repositories', $this->fixture('installation_repositories.removed'));

        $this->assertSame(DeliveryStatus::Processed, $delivery->status);
    }

    public function test_a_repository_that_moves_to_another_installation_follows_it(): void
    {
        $other = Installation::factory()->for($this->githubApp, 'app')->create(['github_installation_id' => 999]);
        Repository::factory()->for($other)->create(['github_repo_id' => 186853004, 'full_name' => 'old-owner/new-service']);

        $this->process('installation_repositories', $this->fixture('installation_repositories.added'));

        $repo = Repository::where('github_repo_id', 186853004)->firstOrFail();
        $this->assertSame('octo-org/new-service', $repo->full_name);
        $this->assertSame(self::INSTALLATION, $repo->installation->github_installation_id);
        $this->assertSame(1, Repository::where('github_repo_id', 186853004)->count());
    }

    // ---------------------------------------------------------------- repository events

    public function test_renamed_privatized_and_publicized_update_known_repositories(): void
    {
        $this->process('installation', $this->fixture('installation.created'));

        $this->process('repository', $this->fixture('repository.renamed'));
        $this->assertContains('octo-org/renamed-workflow', $this->repoNames());
        $this->assertNotContains('octo-org/example-workflow', $this->repoNames());

        $this->process('repository', $this->fixture('repository.privatized'));
        $this->assertTrue(Repository::where('github_repo_id', 186853003)->firstOrFail()->private);

        $this->process('repository', $this->fixture('repository.publicized'));
        $this->assertFalse(Repository::where('github_repo_id', 186853002)->firstOrFail()->private);
    }

    public function test_deleted_removes_the_repository(): void
    {
        $this->process('installation', $this->fixture('installation.created'));

        $this->process('repository', $this->fixture('repository.deleted'));

        $this->assertSame(['octo-org/example-workflow'], $this->repoNames());
    }

    public function test_events_about_repositories_we_do_not_know_change_nothing(): void
    {
        $delivery = $this->process('repository', $this->fixture('repository.renamed'));

        $this->assertSame(DeliveryStatus::Processed, $delivery->status);
        $this->assertSame(0, Repository::count());
    }

    public function test_created_adds_a_repository_only_for_all_repository_installations(): void
    {
        $this->existingInstallation(RepositorySelection::Selected);
        $this->process('repository', $this->fixture('repository.created'));
        $this->assertSame(0, Repository::count(), 'selected access: GitHub sends installation_repositories instead');

        $this->installation()->update(['repository_selection' => RepositorySelection::All]);
        $this->process('repository', $this->fixture('repository.created'));
        $this->assertSame(['octo-org/brand-new'], $this->repoNames());

        $this->installation()->update(['status' => InstallationStatus::Suspended]);
        Repository::query()->delete();
        $this->process('repository', $this->fixture('repository.created'));
        $this->assertSame(0, Repository::count(), 'suspended installations do not gain repositories');
    }

    public function test_a_created_event_without_an_installation_is_ignored_safely(): void
    {
        $delivery = $this->process('repository', $this->fixture('repository.created', function (array $p) {
            unset($p['installation']);

            return $p;
        }));

        $this->assertSame(DeliveryStatus::Processed, $delivery->status);
        $this->assertSame(0, Repository::count());
    }

    public function test_unknown_repository_actions_refresh_whatever_the_payload_says(): void
    {
        $this->process('installation', $this->fixture('installation.created'));

        $this->process('repository', $this->fixture('repository.publicized', function (array $p) {
            $p['action'] = 'some_future_action';

            return $p;
        }));

        $this->assertFalse(Repository::where('github_repo_id', 186853002)->firstOrFail()->private);
    }

    // ---------------------------------------------------------------- idempotency and failures

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function everyEvent(): array
    {
        return [
            'installation.created' => ['installation', 'installation.created'],
            'installation.deleted' => ['installation', 'installation.deleted'],
            'installation.suspend' => ['installation', 'installation.suspend'],
            'installation.unsuspend' => ['installation', 'installation.unsuspend'],
            'installation_repositories.added' => ['installation_repositories', 'installation_repositories.added'],
            'installation_repositories.removed' => ['installation_repositories', 'installation_repositories.removed'],
            'repository.renamed' => ['repository', 'repository.renamed'],
            'repository.privatized' => ['repository', 'repository.privatized'],
            'repository.deleted' => ['repository', 'repository.deleted'],
        ];
    }

    #[DataProvider('everyEvent')]
    public function test_processing_the_same_delivery_again_changes_nothing(string $event, string $fixture): void
    {
        $this->process('installation', $this->fixture('installation.created'));
        $delivery = $this->process($event, $this->fixture($fixture));
        $snapshot = $this->state();

        $this->process($event, $this->fixture($fixture), $delivery); // a replay of the very same delivery
        $this->process($event, $this->fixture($fixture));             // and a separate delivery with the same content

        $this->assertSame($snapshot, $this->state());
    }

    /**
     * @return array<string, mixed>
     */
    private function state(): array
    {
        return [
            'installations' => Installation::orderBy('github_installation_id')->get(['github_installation_id', 'account_login', 'status', 'repository_selection'])->toArray(),
            'repositories' => Repository::orderBy('github_repo_id')->get(['github_repo_id', 'full_name', 'private'])->toArray(),
        ];
    }

    public function test_a_payload_missing_essential_fields_fails_the_delivery_with_a_readable_error(): void
    {
        $delivery = WebhookDelivery::factory()->create(['github_app_id' => $this->githubApp->id, 'event' => 'installation', 'payload' => ['action' => 'created'], 'status' => DeliveryStatus::Received]);

        try {
            (new ProcessWebhookDelivery($delivery->id))->withFakeQueueInteractions()->handle(app(WebhookHandlers::class));
            $this->fail('expected the malformed payload to fail');
        } catch (\Throwable) {
            $this->assertSame(DeliveryStatus::Failed, $delivery->fresh()->status);
            $this->assertNotEmpty($delivery->fresh()->error);
        }
        $this->assertSame(0, Installation::count());
    }

    public function test_a_delivery_whose_app_was_removed_is_processed_without_effect(): void
    {
        $delivery = WebhookDelivery::factory()->create(['github_app_id' => null, 'event' => 'installation', 'payload' => $this->fixture('installation.created'), 'status' => DeliveryStatus::Received]);

        $this->process('installation', $delivery->payload, $delivery);

        $this->assertSame(DeliveryStatus::Processed, $delivery->fresh()->status);
        $this->assertSame(0, Installation::count());
    }

    public function test_thousands_of_repositories_are_stored_in_one_run(): void
    {
        $payload = $this->fixture('installation.created', function (array $p) {
            $p['repositories'] = array_map(fn ($i) => ['id' => 5_000_000 + $i, 'name' => "repo{$i}", 'full_name' => "octo-org/repo{$i}", 'private' => $i % 2 === 0], range(1, 2500));

            return $p;
        });

        $delivery = $this->process('installation', $payload);

        $this->assertSame(DeliveryStatus::Processed, $delivery->status);
        $this->assertSame(2500, Repository::count());
        $this->assertSame(1250, Repository::where('private', false)->count());
    }

    public function test_the_whole_path_works_from_a_real_signed_webhook_request(): void
    {
        $body = json_encode($this->fixture('installation.created'));
        $secret = $this->githubApp->webhook_secret;

        // Queue left on the sync driver: the handler runs when the request is accepted.
        $this->call('POST', '/webhooks/github', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'installation',
            'HTTP_X_GITHUB_DELIVERY' => '72d3162e-cc78-11e3-81ab-4c9367dc0958',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
            'HTTP_X_GITHUB_HOOK_INSTALLATION_TARGET_ID' => '837201',
        ], $body)->assertOk();

        $this->assertSame(DeliveryStatus::Processed, WebhookDelivery::firstOrFail()->status);
        $this->assertSame(['octo-org/example-workflow', 'octo-org/public-docs'], $this->repoNames());
    }
}
