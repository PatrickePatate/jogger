<?php

namespace Tests\Browser;

use App\Domain\Jobs\Actions\TransitionJob;
use App\Domain\Jobs\Enums\JobStatus;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\DuskTestCase;

class JobLiveUpdateTest extends DuskTestCase
{
    use DatabaseMigrations;

    #[Test]
    public function a_status_change_appears_on_the_jobs_page_without_reload(): void
    {
        $admin = User::factory()->admin()->create();
        $job = WorkflowJob::factory()->create(['job_name' => 'live-update-job']);

        $this->browse(function (Browser $browser) use ($admin, $job) {
            $browser->loginAs($admin)
                ->visit('/jobs')
                ->waitForText('live-update-job')
                ->assertSeeIn('tbody', 'Queued')
                ->pause(1500); // let the websocket subscribe

            $browser->script('window.__noReload = true');

            (new TransitionJob)($job, JobStatus::InProgress);

            $browser->waitForTextIn('tbody', 'Running', 2)
                ->assertScript('window.__noReload === true');

            $this->assertNoCspViolations($browser);
        });
    }
}
