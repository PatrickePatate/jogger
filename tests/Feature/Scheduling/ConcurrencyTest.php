<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Domain\Github\Models\Installation;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Domain\Hosts\Models\Host;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Actions\ProvisionRunner;
use App\Domain\Runners\Models\Runner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Throwable;

/**
 * Real concurrency: forked processes with their own database connections race to provision runners for one pool.
 * This cannot run inside the usual per-test transaction (other processes would not see its rows), so the test
 * commits its data and truncates afterwards.
 */
class ConcurrencyTest extends TestCase
{
    private const PROCESSES = 10;

    private const ATTEMPTS_EACH = 5;

    private const ITERATIONS = 20;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl is required to fork concurrent provisioners.');
        }
        $this->artisan('migrate');
        $this->truncate();
        Queue::fake();
    }

    protected function tearDown(): void
    {
        $this->truncate();

        parent::tearDown();
    }

    private function truncate(): void
    {
        DB::statement('TRUNCATE runners, host_commands, runner_pools, hosts, installations, github_apps, audit_logs CASCADE');
    }

    public function test_fifty_concurrent_requests_never_exceed_max_runners(): void
    {
        FakeGithubGateway::install();
        $pool = RunnerPool::factory()->create(['installation_id' => Installation::factory()->create()->id, 'max_runners' => 5]);
        Host::factory()->create(['cpu_total' => 256, 'memory_total_mb' => 1_000_000, 'cpu_used' => 0, 'memory_used_mb' => 0]);

        for ($i = 1; $i <= self::ITERATIONS; $i++) {
            Runner::query()->delete();
            DB::disconnect();

            $children = [];
            for ($p = 0; $p < self::PROCESSES; $p++) {
                $pid = pcntl_fork();
                if ($pid === 0) {
                    // Child: its own connection, then hammer the pool.
                    try {
                        DB::reconnect();
                        for ($a = 0; $a < self::ATTEMPTS_EACH; $a++) {
                            app(ProvisionRunner::class)($pool);
                        }
                        DB::disconnect();
                    } catch (Throwable $e) {
                        fwrite(STDERR, $e->getMessage()."\n");
                        posix_kill((int) getmypid(), SIGKILL);
                    }
                    // Leave without running the parent's shutdown handlers (PHPUnit's result printing).
                    posix_kill((int) getmypid(), SIGKILL);
                }
                $children[] = $pid;
            }
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
            }

            DB::reconnect();
            $this->assertSame(5, Runner::query()->where('pool_id', $pool->id)->live()->count(), "iteration {$i}");
            $this->assertSame(5, Runner::query()->count(), "iteration {$i}: no extra rows were created and failed");
        }
    }
}
