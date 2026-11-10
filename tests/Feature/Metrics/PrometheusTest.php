<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PrometheusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['jogger.metrics_token' => 'scrape-token']);
    }

    public function test_it_requires_the_token(): void
    {
        $this->get('/metrics')->assertUnauthorized();
        $this->withToken('wrong')->get('/metrics')->assertUnauthorized();
        $this->flushHeaders();

        config(['jogger.metrics_token' => null]);
        $this->withToken('scrape-token')->get('/metrics')->assertNotFound();
    }

    public function test_it_exposes_the_expected_series_in_valid_text_format(): void
    {
        $installation = Installation::factory()->create(['account_login' => 'acme']);
        $repo = Repository::factory()->create(['installation_id' => $installation->id]);
        $pool = RunnerPool::factory()->create(['installation_id' => $installation->id]);
        Host::factory()->create(['name' => 'build "01"', 'status' => HostStatus::Online, 'cpu_total' => 8, 'cpu_used' => 1.5, 'memory_total_mb' => 16384, 'disk_free_gb' => 100]);
        Runner::factory()->count(2)->create(['pool_id' => $pool->id, 'state' => RunnerState::Busy]);
        Runner::factory()->create(['pool_id' => $pool->id, 'state' => RunnerState::Gone]);
        WorkflowJob::factory()->count(3)->create(['repository_id' => $repo->id, 'status' => JobStatus::Queued]);
        foreach ([2, 20, 400] as $wait) {
            WorkflowJob::factory()->create(['repository_id' => $repo->id, 'status' => JobStatus::InProgress, 'queued_at' => now()->subSeconds($wait + 60), 'started_at' => now()->subSeconds(60)]);
        }
        Cache::put('github.rate.'.$installation->github_installation_id, ['remaining' => 4321, 'reset' => now()->addHour()->timestamp], 3600);

        $body = $this->withToken('scrape-token')->get('/metrics')->assertOk()
            ->assertHeader('Content-Type', 'text/plain; version=0.0.4; charset=utf-8')->getContent();

        foreach ([
            'jogger_runners{state="busy"} 2',
            'jogger_runners{state="idle"} 0',
            'jogger_jobs{status="queued"} 3',
            'jogger_queue_depth 3',
            'jogger_queue_wait_seconds_bucket{le="5"} 1',
            'jogger_queue_wait_seconds_bucket{le="30"} 2',
            'jogger_queue_wait_seconds_bucket{le="+Inf"} 3',
            'jogger_queue_wait_seconds_count 3',
            'jogger_queue_wait_seconds_sum 422',
            'jogger_host_up{host="build \"01\"",status="online"} 1',
            'jogger_host_cpu_used_cores{host="build \"01\""} 1.5',
            'jogger_host_memory_bytes{host="build \"01\""} 17179869184',
            'jogger_github_rate_limit_remaining{installation="acme"} 4321',
        ] as $line) {
            $this->assertStringContainsString($line."\n", $body);
        }
        $this->assertValidExposition($body);
    }

    /** A strict reading of the text format: every sample belongs to a declared metric and parses. */
    private function assertValidExposition(string $body): void
    {
        $this->assertStringEndsWith("\n", $body);
        $types = [];
        $helped = [];
        foreach (explode("\n", rtrim($body, "\n")) as $line) {
            if (preg_match('/^# HELP ([a-zA-Z_:][a-zA-Z0-9_:]*) .+$/', $line, $m) === 1) {
                $this->assertArrayNotHasKey($m[1], $helped, "duplicate HELP for {$m[1]}");
                $helped[$m[1]] = true;

                continue;
            }
            if (preg_match('/^# TYPE ([a-zA-Z_:][a-zA-Z0-9_:]*) (counter|gauge|histogram|summary|untyped)$/', $line, $m) === 1) {
                $this->assertArrayNotHasKey($m[1], $types, "duplicate TYPE for {$m[1]}");
                $types[$m[1]] = $m[2];

                continue;
            }
            $ok = preg_match('/^([a-zA-Z_:][a-zA-Z0-9_:]*)(\{([a-zA-Z_][a-zA-Z0-9_]*="(\\\\.|[^"\\\\])*"(,[a-zA-Z_][a-zA-Z0-9_]*="(\\\\.|[^"\\\\])*")*)?\})? (-?[0-9]+(\.[0-9]+)?([eE][+-]?[0-9]+)?|NaN|[+-]Inf)$/', $line, $m);
            $this->assertSame(1, $ok, "invalid sample line: {$line}");
            $base = preg_replace('/_(bucket|sum|count)$/', '', $m[1]);
            $this->assertTrue(isset($types[$m[1]]) || (isset($types[$base]) && $types[$base] === 'histogram'), "sample without TYPE: {$line}");
        }
    }
}
