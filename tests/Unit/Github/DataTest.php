<?php

declare(strict_types=1);

namespace Tests\Unit\Github;

use App\Domain\Github\Data\InstallationData;
use App\Domain\Github\Data\JitConfig;
use App\Domain\Github\Data\RepositoryData;
use App\Domain\Github\Data\WorkflowJobData;
use App\Domain\Github\Enums\AccountType;
use App\Domain\Github\Enums\InstallationStatus;
use App\Domain\Github\Enums\RepositorySelection;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use Tests\TestCase;

class DataTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/github/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_queued_workflow_job_maps_ids_labels_urls_and_action(): void
    {
        $job = WorkflowJobData::from($this->fixture('workflow_job.queued'));

        $this->assertSame('queued', $job->action);
        $this->assertSame(399444496, $job->jobId);
        $this->assertSame(29679449, $job->runId);
        $this->assertSame(1, $job->runAttempt);
        $this->assertSame('build (php 8.5)', $job->name);
        $this->assertSame('CI', $job->workflowName);
        $this->assertSame('feature/runners', $job->headBranch);
        $this->assertSame('58a2cb8d2d3ad9ee1b6ac8fc0f9c7eb1d0b1a64a', $job->headSha);
        $this->assertSame('queued', $job->status);
        $this->assertNull($job->conclusion);
        $this->assertSame(['self-hosted', 'linux', 'x64', 'jogger-large'], $job->labels);
        $this->assertSame('https://api.github.com/repos/octo-org/example-workflow/actions/runs/29679449', $job->runUrl);
        $this->assertSame('https://github.com/octo-org/example-workflow/actions/runs/29679449/job/399444496', $job->htmlUrl);
        $this->assertSame(186853002, $job->repositoryId);
        $this->assertSame('octo-org/example-workflow', $job->repositoryFullName);
        $this->assertTrue($job->repositoryPrivate);
        $this->assertSame(4730226, $job->installationId);
        $this->assertSame('octocat', $job->actor);
        $this->assertNull($job->runnerId);
        $this->assertNull($job->runnerName);
        $this->assertSame('2026-11-09T10:00:00+00:00', $job->createdAt?->toIso8601String());
        $this->assertNull($job->completedAt);
    }

    public function test_in_progress_and_completed_carry_runner_and_timing_details(): void
    {
        $running = WorkflowJobData::from($this->fixture('workflow_job.in_progress'));
        $this->assertSame('in_progress', $running->action);
        $this->assertSame(21, $running->runnerId);
        $this->assertSame('jogger-9f2c1a7e', $running->runnerName);
        $this->assertSame(1, $running->runnerGroupId);
        $this->assertSame('2026-11-09T10:00:08+00:00', $running->startedAt?->toIso8601String());

        $done = WorkflowJobData::from($this->fixture('workflow_job.completed'));
        $this->assertSame('completed', $done->action);
        $this->assertSame('success', $done->conclusion);
        $this->assertSame('2026-11-09T10:03:41+00:00', $done->completedAt?->toIso8601String());
    }

    public function test_unknown_extra_fields_are_ignored_at_every_level(): void
    {
        $payload = $this->fixture('workflow_job.queued');
        $payload['brand_new_top_level'] = ['x' => 1];
        $payload['workflow_job']['brand_new_job_field'] = 'hello';
        $payload['repository']['brand_new_repo_field'] = true;

        $job = WorkflowJobData::from($payload);

        $this->assertSame(399444496, $job->jobId);
        $this->assertArrayNotHasKey('brand_new_top_level', $job->toArray());
        $this->assertArrayNotHasKey('brand_new_job_field', $job->toArray());
    }

    public function test_a_payload_without_the_essentials_fails_loudly(): void
    {
        $this->expectException(CannotCreateData::class);

        WorkflowJobData::from(['action' => 'queued', 'workflow_job' => ['name' => 'x']]);
    }

    public function test_self_hosted_detection_and_label_coverage(): void
    {
        $job = WorkflowJobData::from($this->fixture('workflow_job.queued'));

        $this->assertTrue($job->isSelfHosted());
        $this->assertTrue($job->labelsCoveredBy(['Self-Hosted', 'LINUX', 'x64', 'jogger-large', 'extra']));
        $this->assertFalse($job->labelsCoveredBy(['self-hosted', 'linux', 'x64']));

        $hosted = $this->fixture('workflow_job.queued');
        $hosted['workflow_job']['labels'] = ['ubuntu-latest'];
        $this->assertFalse(WorkflowJobData::from($hosted)->isSelfHosted());
    }

    public function test_installation_created_maps_account_selection_and_repositories(): void
    {
        $installation = InstallationData::from($this->fixture('installation.created'));

        $this->assertSame(4730226, $installation->id);
        $this->assertSame('octo-org', $installation->accountLogin);
        $this->assertSame(AccountType::Organization, $installation->accountType);
        $this->assertSame(RepositorySelection::Selected, $installation->repositorySelection);
        $this->assertSame(837201, $installation->appId);
        $this->assertSame('created', $installation->action);
        $this->assertSame(InstallationStatus::Active, $installation->status());
        $this->assertCount(2, $installation->repositories);
        $this->assertSame('octo-org/public-docs', $installation->repositories[1]->fullName);
        $this->assertFalse($installation->repositories[1]->private);
        $this->assertSame('octo-org', $installation->repositories[0]->owner());
    }

    #[DataProvider('statusCases')]
    public function test_installation_status_follows_the_event(string $fixture, InstallationStatus $expected): void
    {
        $this->assertSame($expected, InstallationData::from($this->fixture($fixture))->status());
    }

    /**
     * @return array<string, array{0: string, 1: InstallationStatus}>
     */
    public static function statusCases(): array
    {
        return [
            'created' => ['installation.created', InstallationStatus::Active],
            'deleted' => ['installation.deleted', InstallationStatus::Deleted],
            'suspend' => ['installation.suspend', InstallationStatus::Suspended],
        ];
    }

    public function test_a_user_account_installation_is_recognised(): void
    {
        $payload = $this->fixture('installation.created');
        $payload['installation']['account']['type'] = 'User';

        $this->assertSame(AccountType::User, InstallationData::from($payload)->accountType);
        $this->assertSame(AccountType::User, AccountType::fromGithub('Bot'));
    }

    public function test_installation_repositories_payloads_map_added_removed_and_the_authoritative_selection(): void
    {
        $payload = $this->fixture('installation_repositories.added');
        $payload['repositories_removed'] = [['id' => 7, 'full_name' => 'octo-org/old', 'private' => true]];
        $payload['repository_selection'] = 'all';             // top-level value wins over the nested installation's

        $installation = InstallationData::from($payload);

        $this->assertSame('added', $installation->action);
        $this->assertSame(RepositorySelection::All, $installation->repositorySelection);
        $this->assertSame(['octo-org/new-service', 'octo-org/open-docs'], array_map(fn ($r) => $r->fullName, $installation->repositories));
        $this->assertSame(['octo-org/old'], array_map(fn ($r) => $r->fullName, $installation->repositoriesRemoved));
    }

    public function test_a_bare_installation_object_from_the_api_also_maps(): void
    {
        $bare = $this->fixture('installation.created')['installation'];

        $installation = InstallationData::from($bare);

        $this->assertSame(4730226, $installation->id);
        $this->assertSame('octo-org', $installation->accountLogin);
        $this->assertSame([], $installation->repositories);
    }

    public function test_repository_data_maps_snake_case_and_defaults_unknown_visibility_to_private(): void
    {
        $repo = RepositoryData::from(['id' => 1, 'full_name' => 'a/b', 'name' => 'b', 'node_id' => 'x']);

        $this->assertSame('a/b', $repo->fullName);
        $this->assertTrue($repo->private);
    }

    public function test_jit_config_maps_the_api_response_and_never_prints_the_secret(): void
    {
        $jit = JitConfig::from($this->fixture('jitconfig.response'));

        $this->assertSame(23, $jit->runnerId);
        $this->assertSame('jogger-9f2c1a7e', $jit->name);
        $this->assertSame(['self-hosted', 'linux', 'x64', 'jogger-large'], $jit->labels);
        $this->assertStringStartsWith('YWJj', $jit->encodedJitConfig);

        $this->assertStringNotContainsString('YWJj', print_r($jit, true));
        $this->assertStringNotContainsString('YWJj', var_export($jit->__debugInfo(), true));
        $this->assertStringContainsString('[redacted]', print_r($jit, true));
    }
}
