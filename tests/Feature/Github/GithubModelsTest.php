<?php

declare(strict_types=1);

namespace Tests\Feature\Github;

use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Domain\Github\Enums\AccountType;
use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Enums\InstallationStatus;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Models\WebhookDelivery;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class GithubModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_app_secrets_are_encrypted_at_rest_and_readable_through_the_model(): void
    {
        $app = GithubApp::factory()->create([
            'client_secret' => 'super-secret-client',
            'private_key' => "-----BEGIN RSA PRIVATE KEY-----\nabc123\n-----END RSA PRIVATE KEY-----",
            'webhook_secret' => 'super-secret-webhook',
        ]);

        $raw = (array) DB::table('github_apps')->where('id', $app->id)->first();

        foreach (['client_secret' => 'super-secret-client', 'private_key' => 'abc123', 'webhook_secret' => 'super-secret-webhook'] as $column => $plain) {
            $this->assertNotSame($plain, $raw[$column]);
            $this->assertStringNotContainsString($plain, (string) $raw[$column]);
            $this->assertStringStartsWith('eyJ', (string) $raw[$column]); // Laravel's encrypted payload envelope
        }

        $fresh = GithubApp::findOrFail($app->id);
        $this->assertSame('super-secret-client', $fresh->client_secret);
        $this->assertSame('super-secret-webhook', $fresh->webhook_secret);
        $this->assertStringContainsString('abc123', $fresh->private_key);
    }

    public function test_app_secrets_are_hidden_from_serialization(): void
    {
        $array = GithubApp::factory()->create()->toArray();

        foreach (['client_secret', 'private_key', 'webhook_secret'] as $secret) {
            $this->assertArrayNotHasKey($secret, $array);
        }
        $this->assertArrayHasKey('slug', $array);
    }

    public function test_the_same_plaintext_encrypts_differently_each_time(): void
    {
        $a = GithubApp::factory()->create(['webhook_secret' => 'same']);
        $b = GithubApp::factory()->create(['webhook_secret' => 'same']);

        $this->assertNotSame(
            DB::table('github_apps')->where('id', $a->id)->value('webhook_secret'),
            DB::table('github_apps')->where('id', $b->id)->value('webhook_secret'),
        );
    }

    public function test_factory_can_produce_a_real_signing_key(): void
    {
        $app = GithubApp::factory()->withRealKey()->create();

        $this->assertNotFalse(openssl_pkey_get_private($app->fresh()->private_key));
    }

    public function test_relationships_and_enum_casts(): void
    {
        $installation = Installation::factory()->forUser()->create();
        $repo = Repository::factory()->for($installation)->create(['full_name' => 'octocat/hello']);

        $this->assertSame(AccountType::User, $installation->fresh()->account_type);
        $this->assertTrue($installation->isActive());
        $this->assertTrue(Installation::factory()->suspended()->make()->status === InstallationStatus::Suspended);
        $this->assertTrue($installation->app->installations->contains($installation));
        $this->assertTrue($installation->repositories->contains($repo));
        $this->assertSame('octocat', $repo->owner());
        $this->assertSame('hello', $repo->name());
        $this->assertTrue($repo->installation->is($installation));
    }

    public function test_deleting_an_app_or_installation_cascades(): void
    {
        $repo = Repository::factory()->create();
        $installation = $repo->installation;

        $installation->app->delete();

        $this->assertDatabaseMissing('installations', ['id' => $installation->id]);
        $this->assertDatabaseMissing('repositories', ['id' => $repo->id]);
    }

    public function test_github_ids_are_unique(): void
    {
        $installation = Installation::factory()->create();
        Repository::factory()->for($installation)->create(['github_repo_id' => 42]);

        $this->expectException(QueryException::class);
        Repository::factory()->for($installation)->create(['github_repo_id' => 42]);
    }

    public function test_installation_id_and_app_id_are_unique(): void
    {
        $app = GithubApp::factory()->create(['app_id' => 7]);
        Installation::factory()->for($app, 'app')->create(['github_installation_id' => 99]);

        $this->expectException(QueryException::class);
        Installation::factory()->for($app, 'app')->create(['github_installation_id' => 99]);
    }

    public function test_the_database_rejects_invalid_enum_values(): void
    {
        $app = GithubApp::factory()->create();

        $this->expectException(QueryException::class);
        DB::table('installations')->insert([
            'id' => (string) Str::uuid7(), 'github_app_id' => $app->id, 'github_installation_id' => 1,
            'account_login' => 'x', 'account_type' => 'robot', 'status' => 'active', 'repository_selection' => 'all',
        ]);
    }

    public function test_webhook_deliveries_store_payloads_dedupe_by_delivery_id_and_track_status(): void
    {
        $payload = json_decode((string) file_get_contents(base_path('tests/Fixtures/github/workflow_job.queued.json')), true);
        $delivery = WebhookDelivery::factory()->create(['delivery_id' => '72d3162e-cc78-11e3-81ab-4c9367dc0958', 'payload' => $payload]);

        $fresh = WebhookDelivery::findOrFail($delivery->id);
        $this->assertSame(399444496, $fresh->payload['workflow_job']['id']);
        $this->assertSame(DeliveryStatus::Received, $fresh->status);
        $this->assertNotNull($fresh->received_at);
        $this->assertNull($fresh->processed_at);

        $this->expectException(QueryException::class);
        WebhookDelivery::factory()->create(['delivery_id' => '72d3162e-cc78-11e3-81ab-4c9367dc0958']);
    }

    public function test_deleting_an_app_keeps_its_delivery_history(): void
    {
        $app = GithubApp::factory()->create();
        $delivery = WebhookDelivery::factory()->create(['github_app_id' => $app->id]);

        $app->delete();

        $this->assertNull($delivery->fresh()->github_app_id);
    }

    public function test_installations_are_grantable_to_teams(): void
    {
        $mine = Installation::factory()->create();
        $other = Installation::factory()->create();
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($user, Role::Operator);
        $team->grant($mine);

        $this->assertSame('installation', $mine->grantableType());
        $this->assertEquals([$mine->id], Installation::query()->visibleTo($user)->pluck('id')->all());
        $this->assertSame(Role::Operator, $user->roleFor($mine));
        $this->assertNull($user->roleFor($other));
        $this->assertSame(2, Installation::query()->visibleTo(User::factory()->admin()->create())->count());
    }
}
