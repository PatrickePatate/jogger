<?php

declare(strict_types=1);

namespace Tests\Feature\Github;

use App\Domain\Github\Data\RepositoryData;
use App\Domain\Github\Enums\InstallationStatus;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Livewire\Github\Installations;
use App\Models\User;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubRateLimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InstallationsPageTest extends TestCase
{
    use RefreshDatabase;

    private FakeGithubGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = FakeGithubGateway::install();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_the_empty_state_points_to_the_github_settings(): void
    {
        Livewire::actingAs($this->admin())->test(Installations::class)->assertSee('No installations yet')->assertSee('Open GitHub settings');
    }

    public function test_installations_and_their_repositories_are_listed(): void
    {
        $app = GithubApp::factory()->create(['slug' => 'jogger-acme']);
        $acme = Installation::factory()->for($app, 'app')->create(['account_login' => 'acme', 'synced_at' => now()->subMinutes(3)]);
        $other = Installation::factory()->for($app, 'app')->create(['account_login' => 'zeta']);
        Repository::factory()->for($acme)->create(['full_name' => 'acme/api', 'private' => true]);
        Repository::factory()->for($acme)->create(['full_name' => 'acme/website', 'private' => false]);
        Repository::factory()->for($other)->create(['full_name' => 'zeta/secret']);

        Livewire::actingAs($this->admin())->test(Installations::class)
            ->assertSee('acme')->assertSee('zeta')->assertSee('2 repositories')->assertSee('1 repository')
            ->assertSee('acme/api')->assertSee('acme/website')->assertDontSee('zeta/secret')
            ->assertSee('Private')->assertSee('Public')
            ->assertSee('last synced 3 minutes ago')
            ->assertSee('1 public repository')
            ->assertSeeHtml('href="https://github.com/apps/jogger-acme/installations/new"')
            ->call('select', $other->id)->assertSee('zeta/secret')->assertDontSee('acme/api')->assertSee('not synced from GitHub yet')->assertDontSee('public repository');
    }

    public function test_repositories_can_be_filtered_and_are_paginated(): void
    {
        $installation = Installation::factory()->create();
        foreach (range(1, 60) as $i) {
            Repository::factory()->for($installation)->create(['full_name' => sprintf('acme/repo-%02d', $i)]);
        }
        Repository::factory()->for($installation)->create(['full_name' => 'acme/needle']);

        $component = Livewire::actingAs($this->admin())->test(Installations::class);
        $this->assertCount(50, $component->viewData('repositories')->items());
        $this->assertSame(61, $component->viewData('repositories')->total());

        $component->set('search', 'NEEDLE')->assertSee('acme/needle')->assertDontSee('acme/repo-01')
            ->set('search', 'nothing-matches')->assertSee('No repositories match');
    }

    public function test_resync_updates_the_list_and_reports_what_changed(): void
    {
        $installation = Installation::factory()->create();
        Repository::factory()->for($installation)->create(['github_repo_id' => 1, 'full_name' => 'octo/gone']);
        $this->gateway->repositories = [new RepositoryData(2, 'octo/fresh', true), new RepositoryData(3, 'octo/also-fresh', false)];

        Livewire::actingAs($this->admin())->test(Installations::class)
            ->assertSee('octo/gone')
            ->call('resync', $installation->id)
            ->assertDispatched('toast', type: 'success', message: 'Synced 2 repositories: 2 added, 0 updated, 1 removed.')
            ->assertSee('octo/fresh')->assertDontSee('octo/gone');

        $this->gateway->assertCalled('listInstallationRepositories');
    }

    public function test_resync_failures_become_toasts_and_change_nothing(): void
    {
        $installation = Installation::factory()->create();
        Repository::factory()->for($installation)->create();
        $component = Livewire::actingAs($this->admin())->test(Installations::class);

        $this->gateway->failNextWith(new GithubRateLimited('slow', 42));
        $component->call('resync', $installation->id)->assertDispatched('toast', type: 'warning', message: 'GitHub is rate limiting Jogger. Try again in 42 seconds.');

        $this->gateway->failNextWith(new GithubApiError('GitHub denied access: the GitHub App lacks a permission for this action.', 403));
        $component->call('resync', $installation->id)->assertDispatched('toast', type: 'error', message: 'GitHub denied access: the GitHub App lacks a permission for this action.');

        $this->assertSame(1, Repository::count());
    }

    public function test_suspended_and_deleted_installations_explain_themselves_and_cannot_be_resynced(): void
    {
        $suspended = Installation::factory()->suspended()->create(['account_login' => 'aaa']);
        $deleted = Installation::factory()->create(['account_login' => 'bbb', 'status' => InstallationStatus::Deleted]);

        $component = Livewire::actingAs($this->admin())->test(Installations::class)
            ->assertSee('This installation is suspended on GitHub')
            ->call('select', $deleted->id)->assertSee('removed on GitHub');
        $component->assertSeeHtml('disabled');

        $component->call('resync', $suspended->id)->assertDispatched('toast', type: 'error');
        $this->gateway->assertNotCalled('listInstallationRepositories');
    }

    public function test_only_administrators_can_see_or_use_the_page(): void
    {
        $member = User::factory()->create();
        $installation = Installation::factory()->create();

        $this->actingAs($member)->get('/github/installations')->assertForbidden();
        Livewire::actingAs($member)->test(Installations::class)->call('resync', $installation->id)->assertForbidden();
        $this->gateway->assertNotCalled('listInstallationRepositories');

        auth()->logout();
        $this->get('/github/installations')->assertRedirect(route('login'));
        $this->actingAs($this->admin())->get('/github/installations')->assertOk()->assertSee('href="'.route('github.installations').'"', false);
    }

    public function test_the_page_polls_so_webhook_driven_changes_appear_on_their_own(): void
    {
        Livewire::actingAs($this->admin())->test(Installations::class)->assertSeeHtml('wire:poll.10s');
    }
}
