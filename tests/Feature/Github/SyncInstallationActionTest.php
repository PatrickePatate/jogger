<?php

declare(strict_types=1);

namespace Tests\Feature\Github;

use App\Domain\Github\Actions\SyncInstallation;
use App\Domain\Github\Data\RepositoryData;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubRateLimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncInstallationActionTest extends TestCase
{
    use RefreshDatabase;

    private FakeGithubGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = FakeGithubGateway::install();
    }

    public function test_it_adds_updates_and_removes_to_match_github(): void
    {
        $installation = Installation::factory()->create();
        Repository::factory()->for($installation)->create(['github_repo_id' => 1, 'full_name' => 'octo/kept', 'private' => true]);
        Repository::factory()->for($installation)->create(['github_repo_id' => 2, 'full_name' => 'octo/old-name', 'private' => true]);
        Repository::factory()->for($installation)->create(['github_repo_id' => 3, 'full_name' => 'octo/vanished']);
        Repository::factory()->for($installation)->create(['github_repo_id' => 4, 'full_name' => 'octo/now-public', 'private' => true]);
        $this->gateway->repositories = [
            new RepositoryData(1, 'octo/kept', true),
            new RepositoryData(2, 'octo/new-name', true),
            new RepositoryData(4, 'octo/now-public', false),
            new RepositoryData(5, 'octo/brand-new', true),
        ];

        $result = app(SyncInstallation::class)($installation);

        $this->assertSame(1, $result->added);
        $this->assertSame(2, $result->updated);
        $this->assertSame(1, $result->removed);
        $this->assertSame(4, $result->total);
        $this->assertSame(['octo/brand-new', 'octo/kept', 'octo/new-name', 'octo/now-public'], Repository::orderBy('full_name')->pluck('full_name')->all());
        $this->assertFalse(Repository::where('github_repo_id', 4)->firstOrFail()->private);
        $this->assertNotNull($installation->fresh()->synced_at);
        $this->assertStringContainsString('1 added, 2 updated, 1 removed', $result->summary());
    }

    public function test_a_second_run_changes_nothing_and_says_so(): void
    {
        $installation = Installation::factory()->create();
        $this->gateway->repositories = [new RepositoryData(1, 'octo/a', true), new RepositoryData(2, 'octo/b', false)];
        app(SyncInstallation::class)($installation);

        $result = app(SyncInstallation::class)($installation);

        $this->assertSame([0, 0, 0, 2], [$result->added, $result->updated, $result->removed, $result->total]);
        $this->assertSame('Already up to date (2 repositories).', $result->summary());
        $this->assertSame(2, Repository::count());
    }

    public function test_repositories_of_other_installations_are_left_alone(): void
    {
        $mine = Installation::factory()->create();
        $theirs = Installation::factory()->create();
        Repository::factory()->for($theirs)->create(['github_repo_id' => 99, 'full_name' => 'other/repo']);
        $this->gateway->repositories = [new RepositoryData(1, 'octo/a', true)];

        app(SyncInstallation::class)($mine);

        $this->assertSame(1, Repository::where('installation_id', $theirs->id)->count());
        $this->assertSame(1, Repository::where('installation_id', $mine->id)->count());
    }

    public function test_github_returning_no_repositories_empties_the_installation(): void
    {
        $installation = Installation::factory()->create();
        Repository::factory()->count(3)->for($installation)->create();
        $this->gateway->repositories = [];

        $result = app(SyncInstallation::class)($installation);

        $this->assertSame(3, $result->removed);
        $this->assertSame(0, Repository::count());
    }

    public function test_suspended_and_deleted_installations_are_refused_without_calling_github(): void
    {
        foreach ([Installation::factory()->suspended()->create(), Installation::factory()->create(['status' => 'deleted'])] as $installation) {
            try {
                app(SyncInstallation::class)($installation);
                $this->fail('expected a refusal');
            } catch (GithubApiError $e) {
                $this->assertStringContainsString($installation->status->value, $e->getMessage());
            }
        }
        $this->gateway->assertNotCalled('listInstallationRepositories');
    }

    public function test_a_github_failure_changes_nothing(): void
    {
        $installation = Installation::factory()->create();
        Repository::factory()->count(2)->for($installation)->create();
        $this->gateway->failNextWith(new GithubRateLimited('slow', 30));

        try {
            app(SyncInstallation::class)($installation);
            $this->fail('expected GithubRateLimited');
        } catch (GithubRateLimited) {
        }

        $this->assertSame(2, Repository::count());
        $this->assertNull($installation->fresh()->synced_at);
    }

    public function test_a_repository_seen_twice_in_the_listing_is_stored_once(): void
    {
        $installation = Installation::factory()->create();
        $this->gateway->repositories = [new RepositoryData(1, 'octo/a', true), new RepositoryData(1, 'octo/a', true)];

        app(SyncInstallation::class)($installation);

        $this->assertSame(1, Repository::count());
    }
}
