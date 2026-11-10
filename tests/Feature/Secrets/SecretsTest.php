<?php

declare(strict_types=1);

namespace Tests\Feature\Secrets;

use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\AuditLog;
use App\Domain\Access\Models\Team;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Hosts\Models\HostCommand;
use App\Domain\Jobs\Logs\LogIngestor;
use App\Domain\Jobs\Logs\LogStore;
use App\Domain\Jobs\Models\JobLog;
use App\Domain\Pools\Actions\EnsureImageOnHosts;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Actions\ProvisionRunner;
use App\Domain\Runners\Models\Runner;
use App\Domain\Secrets\Actions\ResolveSecretsForPool;
use App\Domain\Secrets\Actions\StoreSecret;
use App\Domain\Secrets\Enums\SecretKind;
use App\Domain\Secrets\Enums\SecretScope;
use App\Domain\Secrets\Models\Secret;
use App\Livewire\Secrets\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class SecretsTest extends TestCase
{
    use RefreshDatabase;

    private RunnerPool $pool;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        FakeGithubGateway::install();
        Storage::fake('logs');
        config(['jogger.log_disk' => 'logs']);
        $this->pool = RunnerPool::factory()->create(['installation_id' => Installation::factory()->create()->id, 'name' => 'linux-pool', 'image' => 'ghcr.io/acme/runner:1']);
        $this->admin = User::factory()->admin()->create();
        $this->actingAs($this->admin);
    }

    private function store(SecretScope $scope, ?string $id, string $name, string $value, SecretKind $kind = SecretKind::Env): Secret
    {
        return app(StoreSecret::class)($scope, $id, $kind, $name, $value);
    }

    public function test_narrower_scopes_override_wider_ones(): void
    {
        $this->store(SecretScope::Global, null, 'TOKEN', 'global-value');
        $this->store(SecretScope::Global, null, 'ONLY_GLOBAL', 'g');
        $this->store(SecretScope::Installation, $this->pool->installation_id, 'TOKEN', 'installation-value');
        $this->store(SecretScope::Installation, $this->pool->installation_id, 'ONLY_INSTALLATION', 'i');
        $this->store(SecretScope::Pool, $this->pool->id, 'TOKEN', 'pool-value');
        $other = RunnerPool::factory()->create(['installation_id' => Installation::factory()->create()->id]);
        $this->store(SecretScope::Pool, $other->id, 'FOREIGN', 'x');

        $resolver = app(ResolveSecretsForPool::class);

        $this->assertEquals(['TOKEN' => 'pool-value', 'ONLY_GLOBAL' => 'g', 'ONLY_INSTALLATION' => 'i'], $resolver->envFor($this->pool));
        $this->assertEquals(['TOKEN' => 'global-value', 'ONLY_GLOBAL' => 'g', 'FOREIGN' => 'x'], $resolver->envFor($other));
    }

    public function test_values_are_encrypted_at_rest_and_hidden(): void
    {
        $secret = $this->store(SecretScope::Global, null, 'TOKEN', 'plain-text-value');

        $raw = DB::table('secrets')->where('id', $secret->id)->value('value');
        $this->assertStringNotContainsString('plain-text-value', $raw);
        $this->assertArrayNotHasKey('value', $secret->toArray());
        $this->assertStringNotContainsString('plain-text-value', (string) json_encode(AuditLog::query()->get()));
    }

    public function test_names_are_validated(): void
    {
        foreach (['1BAD', 'has space', 'GITHUB_TOKEN', 'JIT_CONFIG'] as $name) {
            try {
                $this->store(SecretScope::Global, null, $name, 'v');
                $this->fail("{$name} was accepted");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('name', $e->errors());
            }
        }
    }

    public function test_runner_start_carries_env_and_registry_credentials_and_they_are_scrubbed_on_ack(): void
    {
        $host = Host::factory()->create(['status' => HostStatus::Online, 'credential_hash' => Host::hashCredential('jha_secret')]);
        $this->store(SecretScope::Pool, $this->pool->id, 'HTTPS_PROXY', 'http://proxy.internal:3128');
        $this->store(SecretScope::Global, null, 'ghcr.io', (string) json_encode(['username' => 'bot', 'password' => 'registry-pass']), SecretKind::Registry);
        $this->store(SecretScope::Global, null, 'quay.io', (string) json_encode(['username' => 'other', 'password' => 'nope']), SecretKind::Registry);

        $runner = app(ProvisionRunner::class)($this->pool);
        $this->assertNotNull($runner);

        $command = HostCommand::query()->where('type', 'runner.start')->firstOrFail();
        $this->assertSame(['HTTPS_PROXY' => 'http://proxy.internal:3128'], (array) $command->payload['env']);
        $this->assertEquals(['server' => 'ghcr.io', 'username' => 'bot', 'password' => 'registry-pass'], $command->payload['registry_auth']);

        app(EnsureImageOnHosts::class)($this->pool, force: true);
        $pull = HostCommand::query()->where('type', 'image.pull')->firstOrFail();
        $this->assertSame('bot', $pull->payload['registry_auth']['username']);

        $this->withToken('jha_secret')->postJson("/agent/v1/commands/{$command->id}/ack", ['ok' => true, 'result' => ['container_id' => 'abc']])->assertSuccessful();
        $payload = $command->fresh()->payload;
        $this->assertArrayNotHasKey('env', $payload);
        $this->assertArrayNotHasKey('registry_auth', $payload);
    }

    public function test_rotation_reaches_new_runners_only_and_both_values_stay_masked(): void
    {
        Host::factory()->create(['status' => HostStatus::Online]);
        $this->store(SecretScope::Pool, $this->pool->id, 'API_KEY', 'first-secret-value');
        $old = app(ProvisionRunner::class)($this->pool);

        $rotated = $this->store(SecretScope::Pool, $this->pool->id, 'API_KEY', 'second-secret-value');
        $new = app(ProvisionRunner::class)($this->pool);

        $this->assertNotNull($rotated->rotated_at);
        $this->assertSame(1, Secret::query()->count());
        $payloads = HostCommand::query()->where('type', 'runner.start')->get()->keyBy(fn (HostCommand $c) => $c->payload['runner_id']);
        $this->assertSame('first-secret-value', $payloads[$old->id]->payload['env']->API_KEY ?? $payloads[$old->id]->payload['env']['API_KEY']);
        $this->assertSame('second-secret-value', $payloads[$new->id]->payload['env']->API_KEY ?? $payloads[$new->id]->payload['env']['API_KEY']);

        $masked = app(ResolveSecretsForPool::class)->forRunner($old->id);
        $this->assertEqualsCanonicalizing(['first-secret-value', 'second-secret-value'], $masked);
    }

    public function test_secret_values_are_masked_in_job_logs(): void
    {
        $runner = Runner::factory()->create(['pool_id' => $this->pool->id]);
        $this->store(SecretScope::Installation, $this->pool->installation_id, 'DEPLOY_TOKEN', 'super-secret-token');

        $host = Host::factory()->create(['status' => HostStatus::Online]);
        $runner->forceFill(['host_id' => $host->id])->save();
        app(LogIngestor::class)->ingest($host, $runner->id, 1, "deploying with super-secret-token now\n");

        $text = app(LogStore::class)->read(JobLog::query()->findOrFail($runner->id));
        $this->assertStringNotContainsString('super-secret-token', $text);
        $this->assertStringContainsString('***', $text);
    }

    public function test_only_operators_of_the_pool_manage_its_secrets_and_only_admins_global_ones(): void
    {
        $operator = User::factory()->create();
        $viewer = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($operator, Role::Operator);
        $team->grant($this->pool->installation);
        $viewers = Team::factory()->create();
        $viewers->addMember($viewer, Role::Viewer);
        $viewers->grant($this->pool->installation);

        Livewire::actingAs($operator)->test(Index::class)
            ->set('scope', 'pool')->set('scopeId', $this->pool->id)->set('name', 'TOKEN')->set('value', 'operator-value')
            ->call('save')->assertHasNoErrors()
            ->assertSet('value', '')
            ->assertSee('TOKEN')->assertDontSee('operator-value');
        $this->assertSame(1, Secret::query()->count());

        Livewire::actingAs($operator)->test(Index::class)
            ->set('scope', 'global')->set('name', 'GLOBAL_TOKEN')->set('value', 'x')
            ->call('save')->assertForbidden();

        $secret = Secret::query()->firstOrFail();
        Livewire::actingAs($viewer)->test(Index::class)
            ->assertSee('TOKEN')->assertDontSee('Rotate')
            ->call('delete', $secret->id)->assertForbidden();

        $outsider = User::factory()->create();
        Livewire::actingAs($outsider)->test(Index::class)->assertDontSee('TOKEN')
            ->set('scope', 'pool')->set('scopeId', $this->pool->id)->set('name', 'SNEAK')->set('value', 'x')
            ->call('save')->assertForbidden();

        Livewire::actingAs($operator)->test(Index::class)->call('delete', $secret->id);
        $this->assertSame(0, Secret::query()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'secret.deleted')->exists());
    }
}
