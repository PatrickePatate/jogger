<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\Team;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Livewire\Shared\CommandPalette;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CommandPaletteTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_on_every_app_page_with_its_shortcut(): void
    {
        $this->actingAs(User::factory()->create())->get('/')
            ->assertOk()
            ->assertSee('aria-label="Command palette"', false)
            ->assertSee("event.key.toLowerCase() === 'k'", false);
    }

    public function test_it_lists_pages_and_filters_them(): void
    {
        $member = User::factory()->create();

        Livewire::actingAs($member)->test(CommandPalette::class)
            ->assertSee('Overview')->assertSee('Runners')->assertDontSee('Users')
            ->set('query', 'tok')
            ->assertSee('API tokens')->assertDontSee('Runners');

        Livewire::actingAs(User::factory()->admin()->create())->test(CommandPalette::class)->assertSee('Users');
    }

    public function test_search_finds_resources_for_an_administrator(): void
    {
        $installation = Installation::factory()->create();
        $repo = Repository::factory()->create(['installation_id' => $installation->id, 'full_name' => 'acme/deploy-tools']);
        WorkflowJob::factory()->create(['repository_id' => $repo->id, 'job_name' => 'deploy-production']);
        RunnerPool::factory()->create(['installation_id' => $installation->id, 'name' => 'deploy-pool']);
        Host::factory()->create(['name' => 'deploy-host']);

        Livewire::actingAs(User::factory()->admin()->create())->test(CommandPalette::class)
            ->set('query', 'deploy')
            ->assertSee('deploy-production')->assertSee('acme/deploy-tools')->assertSee('deploy-pool')->assertSee('deploy-host');
    }

    public function test_search_excludes_what_the_user_cannot_see(): void
    {
        $mine = Installation::factory()->create();
        $theirs = Installation::factory()->create();
        $myRepo = Repository::factory()->create(['installation_id' => $mine->id, 'full_name' => 'mine/deploy']);
        $theirRepo = Repository::factory()->create(['installation_id' => $theirs->id, 'full_name' => 'theirs/deploy']);
        WorkflowJob::factory()->create(['repository_id' => $myRepo->id, 'job_name' => 'deploy-visible']);
        WorkflowJob::factory()->create(['repository_id' => $theirRepo->id, 'job_name' => 'deploy-hidden']);
        RunnerPool::factory()->create(['installation_id' => $theirs->id, 'name' => 'deploy-secret-pool']);
        Host::factory()->create(['name' => 'deploy-host']);

        $member = User::factory()->create();
        $team = Team::factory()->create();
        $team->addMember($member, Role::Viewer);
        $team->grant($mine);

        Livewire::actingAs($member)->test(CommandPalette::class)
            ->set('query', 'deploy')
            ->assertSee('deploy-visible')->assertSee('mine/deploy')
            ->assertDontSee('deploy-hidden')->assertDontSee('theirs/deploy')->assertDontSee('deploy-secret-pool')->assertDontSee('deploy-host');
    }
}
