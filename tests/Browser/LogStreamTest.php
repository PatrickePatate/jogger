<?php

namespace Tests\Browser;

use App\Domain\Hosts\Models\Host;
use App\Domain\Jobs\Logs\LogIngestor;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Runners\Models\Runner;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\DuskTestCase;

class LogStreamTest extends DuskTestCase
{
    use DatabaseMigrations;

    #[Test]
    public function log_lines_appear_in_the_terminal_within_a_second(): void
    {
        $admin = User::factory()->admin()->create();
        $host = Host::factory()->create();
        $runner = Runner::factory()->create(['host_id' => $host->id]);
        $job = WorkflowJob::factory()->running()->create(['runner_id' => $runner->id]);

        $this->browse(function (Browser $browser) use ($admin, $host, $runner, $job) {
            $browser->loginAs($admin)
                ->visit("/jobs/{$job->id}")
                ->waitFor('[role="log"] .xterm-rows')
                ->pause(1500); // let the websocket subscribe

            // Exactly what the agent endpoint does with a batch.
            app(LogIngestor::class)->ingest($host, $runner->id, 1, "hello from the runner\nsecond line\n");

            $browser->waitForTextIn('[role="log"]', 'second line', 1)
                ->assertSeeIn('[role="log"]', 'hello from the runner');

            $this->assertNoCspViolations($browser);
        });
    }
}
