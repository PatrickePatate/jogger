<?php

declare(strict_types=1);

namespace Tests\Feature\Github;

use App\Domain\Github\Models\WebhookDelivery;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PruneTest extends TestCase
{
    use RefreshDatabase;

    public function test_deliveries_older_than_the_retention_period_are_deleted(): void
    {
        $old = WebhookDelivery::factory()->create(['received_at' => now()->subDays(15)]);
        $edge = WebhookDelivery::factory()->create(['received_at' => now()->subDays(14)->addMinute()]);
        $new = WebhookDelivery::factory()->create(['received_at' => now()->subHour()]);

        $this->artisan('jogger:prune')->expectsOutputToContain('Deleted 1 webhook deliveries older than 14 days')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($edge);
        $this->assertModelExists($new);
    }

    public function test_the_retention_period_is_configurable(): void
    {
        config(['jogger.delivery_retention_days' => 2]);
        $old = WebhookDelivery::factory()->create(['received_at' => now()->subDays(3)]);
        $new = WebhookDelivery::factory()->create(['received_at' => now()->subDay()]);

        $this->artisan('jogger:prune')->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($new);
    }

    public function test_pruning_with_nothing_to_delete_is_fine_and_a_zero_setting_cannot_wipe_everything(): void
    {
        config(['jogger.delivery_retention_days' => 0]);
        $fresh = WebhookDelivery::factory()->create(['received_at' => now()->subMinutes(5)]);

        $this->artisan('jogger:prune')->expectsOutputToContain('older than 1 days')->assertSuccessful();

        $this->assertModelExists($fresh); // the minimum retention is one day
    }

    public function test_the_command_is_scheduled_daily(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => str_ends_with($e->command, 'jogger:prune'));

        $this->assertCount(1, $events);
        $this->assertSame('15 3 * * *', $events->first()->expression);
    }
}
