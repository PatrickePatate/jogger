<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Access\Models\AuditLog;
use App\Domain\Cloud\Enums\CloudHostState;
use App\Domain\Cloud\Models\CloudProvider;
use App\Domain\Cloud\Models\HostTemplate;
use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Livewire\Hosts\Index as HostsIndex;
use App\Livewire\Jobs\Index as JobsIndex;
use App\Livewire\Pools\Form as PoolForm;
use App\Livewire\Settings\CloudProviders;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CloudProvidersTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'hcloud-SECRET-token-42';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->admin()->create();
    }

    private function hetznerAccepts(): void
    {
        Http::fake(['api.hetzner.cloud/*' => Http::response(['locations' => []])]);
    }

    private function hetznerRejects(): void
    {
        Http::fake(['api.hetzner.cloud/*' => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'unable to authenticate']], 401)]);
    }

    public function test_non_admins_get_403(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)->get(route('admin.cloud'))->assertForbidden();
    }

    public function test_admins_get_the_page_from_the_navigation(): void
    {
        $this->actingAs($this->admin)->get(route('admin.cloud'))
            ->assertOk()
            ->assertSee('Cloud hosts')
            ->assertSee('Fake (development only)'); // testing environment offers the fake driver
    }

    public function test_a_provider_is_checked_with_its_driver_and_stored_encrypted(): void
    {
        $this->hetznerAccepts();

        Livewire::actingAs($this->admin)->test(CloudProviders::class)
            ->set('providerName', 'Hetzner main')->set('providerDriver', 'hetzner')->set('credentials.token', self::TOKEN)
            ->call('saveProvider')
            ->assertHasNoErrors()
            ->assertDispatched('toast', type: 'success', message: 'Added Hetzner main.')
            ->assertDontSee(self::TOKEN);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.hetzner.cloud/v1/locations')
            && $request->hasHeader('Authorization', 'Bearer '.self::TOKEN));

        $provider = CloudProvider::query()->sole();
        $this->assertSame('hetzner', $provider->driver);
        $this->assertSame(self::TOKEN, $provider->credentials['token']);
        $this->assertStringNotContainsString(self::TOKEN, (string) DB::table('cloud_providers')->value('credentials'));
    }

    public function test_a_rejected_token_saves_nothing_and_shows_the_provider_message(): void
    {
        $this->hetznerRejects();

        Livewire::actingAs($this->admin)->test(CloudProviders::class)
            ->set('providerName', 'Hetzner main')->set('providerDriver', 'hetzner')->set('credentials.token', self::TOKEN)
            ->call('saveProvider')
            ->assertHasErrors(['credentials.token' => 'Hetzner Cloud rejected the API token.'])
            ->assertSee('Hetzner Cloud rejected the API token.')
            ->assertDontSee(self::TOKEN);

        $this->assertSame(0, CloudProvider::query()->count());
    }

    public function test_the_token_is_never_rendered_and_an_empty_token_keeps_the_stored_one(): void
    {
        Http::fake();
        $provider = CloudProvider::factory()->create(['name' => 'Hetzner main', 'driver' => 'hetzner', 'credentials' => ['token' => self::TOKEN]]);

        Livewire::actingAs($this->admin)->test(CloudProviders::class)
            ->call('editProvider', $provider->id)
            ->assertSet('providerName', 'Hetzner main')
            ->assertSee('Leave empty to keep the current token.')
            ->assertDontSee(self::TOKEN)
            ->set('providerName', 'Hetzner renamed')
            ->call('saveProvider')
            ->assertHasNoErrors()
            ->assertDontSee(self::TOKEN);

        $provider->refresh();
        $this->assertSame('Hetzner renamed', $provider->name);
        $this->assertSame(self::TOKEN, $provider->credentials['token']);
        Http::assertNothingSent();
        $this->assertTrue(AuditLog::query()->where('action', 'cloud.provider_updated')->where('metadata->token_changed', false)->exists());
    }

    public function test_a_provider_added_after_the_page_loaded_is_used_by_the_template_form(): void
    {
        $page = Livewire::actingAs($this->admin)->test(CloudProviders::class);
        $provider = CloudProvider::factory()->create();

        $page->set('name', 'cx22-fsn1')->set('server_type', 'cx22')->set('location', 'fsn1')->set('image', 'ubuntu-24.04')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertSame($provider->id, HostTemplate::query()->sole()->cloud_provider_id);
    }

    public function test_a_template_is_created_and_synced_with_its_pools(): void
    {
        $provider = CloudProvider::factory()->create();
        $first = RunnerPool::factory()->create(['name' => 'linux-x64']);
        $second = RunnerPool::factory()->create(['name' => 'arm']);

        $page = Livewire::actingAs($this->admin)->test(CloudProviders::class)
            ->set('name', 'cx22-fsn1')->set('cloud_provider_id', $provider->id)
            ->set('server_type', 'cx22')->set('location', 'fsn1')->set('image', 'ubuntu-24.04')
            ->set('max_hosts', '3')->set('pool_ids', [$first->id])
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $template = HostTemplate::query()->sole();
        $this->assertSame([$first->id], $template->pools()->pluck('runner_pools.id')->all());
        $this->assertSame(['self-hosted', 'linux', 'x64'], $template->labels);
        $this->assertSame(3, $template->max_hosts);

        $page->call('editTemplate', $template->id)
            ->assertSet('name', 'cx22-fsn1')
            ->set('pool_ids', [$second->id])->set('max_hosts', '4')
            ->call('saveTemplate')
            ->assertHasNoErrors();

        $this->assertSame([$second->id], $template->fresh()->pools()->pluck('runner_pools.id')->all());
        $this->assertSame(4, $template->fresh()->max_hosts);
        $this->assertTrue(AuditLog::query()->where('action', 'cloud.template_created')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'cloud.template_updated')->exists());
    }

    public function test_the_maximum_servers_must_cover_the_warm_ones(): void
    {
        $provider = CloudProvider::factory()->create();

        Livewire::actingAs($this->admin)->test(CloudProviders::class)
            ->set('name', 'cx22-fsn1')->set('cloud_provider_id', $provider->id)
            ->set('server_type', 'cx22')->set('location', 'fsn1')->set('image', 'ubuntu-24.04')
            ->set('warm_hosts', '3')->set('max_hosts', '2')
            ->call('saveTemplate')
            ->assertHasErrors(['max_hosts' => 'The maximum must be at least the number of warm servers.']);

        $this->assertSame(0, HostTemplate::query()->count());
    }

    public function test_a_provider_with_open_servers_cannot_be_deleted(): void
    {
        $provider = CloudProvider::factory()->create(['name' => 'Hetzner main']);
        $template = HostTemplate::factory()->create(['cloud_provider_id' => $provider->id]);
        $host = Host::factory()->create(['host_template_id' => $template->id, 'ephemeral' => true, 'cloud_state' => CloudHostState::Online]);

        $page = Livewire::actingAs($this->admin)->test(CloudProviders::class)
            ->call('deleteProvider', $provider->id)
            ->assertDispatched('toast', type: 'error', message: 'Hetzner main still has 1 open server. Wait until they are deleted, then delete the provider.');

        $this->assertDatabaseHas('cloud_providers', ['id' => $provider->id]);

        $host->update(['cloud_state' => CloudHostState::Deleted]);
        $page->call('deleteProvider', $provider->id)->assertDispatched('toast', type: 'success');

        $this->assertDatabaseMissing('cloud_providers', ['id' => $provider->id]);
        $this->assertDatabaseMissing('host_templates', ['id' => $template->id]);
        $this->assertTrue(AuditLog::query()->where('action', 'cloud.provider_deleted')->exists());
    }

    public function test_every_change_is_audited_and_no_audit_row_holds_a_credential(): void
    {
        $this->hetznerAccepts();
        $page = Livewire::actingAs($this->admin)->test(CloudProviders::class)
            ->set('providerName', 'Hetzner main')->set('providerDriver', 'hetzner')->set('credentials.token', self::TOKEN)
            ->call('saveProvider');

        $provider = CloudProvider::query()->sole();
        $page->set('name', 'cx22-fsn1')->set('cloud_provider_id', $provider->id)
            ->set('server_type', 'cx22')->set('location', 'fsn1')->set('image', 'ubuntu-24.04')
            ->call('saveTemplate');
        $template = HostTemplate::query()->sole();
        $page->call('deleteTemplate', $template->id);
        $page->call('deleteProvider', $provider->id);

        $this->assertEqualsCanonicalizing(
            ['cloud.provider_created', 'cloud.template_created', 'cloud.template_deleted', 'cloud.provider_deleted'],
            AuditLog::query()->pluck('action')->all(),
        );
        $this->assertSame($this->admin->id, AuditLog::query()->where('action', 'cloud.provider_created')->value('user_id'));
        $this->assertStringNotContainsString(self::TOKEN, json_encode(AuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR));
    }

    public function test_ephemeral_hosts_show_a_badge_their_template_and_cloud_state(): void
    {
        $template = HostTemplate::factory()->create(['name' => 'cx22-fsn1']);
        Host::factory()->create(['name' => 'cloud-a1', 'ephemeral' => true, 'host_template_id' => $template->id, 'cloud_state' => CloudHostState::Booting]);
        Host::factory()->create(['name' => 'static-1']);

        Livewire::actingAs($this->admin)->test(HostsIndex::class)
            ->assertSee('Ephemeral')
            ->assertSee('cx22-fsn1')
            ->assertSee('Booting')
            ->assertSeeHtml('data-testid="host-runners-cloud-a1"');
    }

    public function test_a_pool_lists_its_cloud_templates_and_admins_get_a_link(): void
    {
        $pool = RunnerPool::factory()->create();
        HostTemplate::factory()->create(['name' => 'cx22-fsn1'])->pools()->attach($pool);

        Livewire::actingAs($this->admin)->test(PoolForm::class, ['pool' => $pool->id])
            ->assertSee('Cloud templates: cx22-fsn1')
            ->assertSeeHtml('href="'.route('admin.cloud').'"');
    }

    public function test_a_queued_job_of_a_pool_with_a_cloud_template_says_it_waits_for_a_server(): void
    {
        $repository = Repository::factory()->create();
        $cloudPool = RunnerPool::factory()->create(['name' => 'cloud-pool']);
        HostTemplate::factory()->create()->pools()->attach($cloudPool);
        $plainPool = RunnerPool::factory()->create(['name' => 'plain-pool']);

        WorkflowJob::factory()->create(['repository_id' => $repository->id, 'job_name' => 'build-cloud', 'pool_id' => $cloudPool->id, 'runner_id' => null]);
        WorkflowJob::factory()->create(['repository_id' => $repository->id, 'job_name' => 'build-plain', 'pool_id' => $plainPool->id, 'runner_id' => null]);

        $html = Livewire::actingAs($this->admin)->test(JobsIndex::class)
            ->assertSee('build-cloud')
            ->assertSee('build-plain')
            ->html();

        $this->assertSame(1, substr_count($html, 'Waiting for a cloud server'));
    }
}
