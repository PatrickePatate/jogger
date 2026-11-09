<?php

declare(strict_types=1);

namespace Tests\Feature\Github;

use App\Domain\Github\Actions\ProcessWebhookDelivery;
use App\Domain\Github\Enums\DeliveryStatus;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Github\Webhooks\HandlePing;
use App\Domain\Github\Webhooks\WebhookHandlers;
use App\Support\Exceptions\GithubRateLimited;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Tests\Support\CountingHandler;
use Tests\TestCase;

class ProcessWebhookDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CountingHandler::reset();
        app(WebhookHandlers::class)->register('workflow_job', CountingHandler::class);
    }

    private function process(WebhookDelivery $delivery): ProcessWebhookDelivery
    {
        $job = (new ProcessWebhookDelivery($delivery->id))->withFakeQueueInteractions();
        $job->handle(app(WebhookHandlers::class));

        return $job;
    }

    private function delivery(string $event = 'workflow_job', DeliveryStatus $status = DeliveryStatus::Received): WebhookDelivery
    {
        return WebhookDelivery::factory()->create(['event' => $event, 'status' => $status]);
    }

    public function test_a_successful_handler_marks_the_delivery_processed(): void
    {
        $delivery = $this->delivery();

        $this->process($delivery);

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Processed, $delivery->status);
        $this->assertNotNull($delivery->processed_at);
        $this->assertNull($delivery->error);
        $this->assertSame(1, CountingHandler::$calls);
    }

    public function test_events_without_a_handler_are_ignored_not_failed(): void
    {
        $delivery = $this->delivery('star');

        $this->process($delivery);

        $this->assertSame(DeliveryStatus::Ignored, $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->processed_at);
    }

    public function test_ping_is_handled_out_of_the_box(): void
    {
        $this->assertInstanceOf(HandlePing::class, app(WebhookHandlers::class)->for('ping'));

        $delivery = $this->delivery('ping');
        $this->process($delivery);

        $this->assertSame(DeliveryStatus::Processed, $delivery->fresh()->status);
    }

    public function test_a_failing_handler_records_the_error_and_rethrows_so_the_queue_retries(): void
    {
        CountingHandler::$throw = new RuntimeException('database exploded');
        $delivery = $this->delivery();

        try {
            $this->process($delivery);
            $this->fail('the exception must reach the queue');
        } catch (RuntimeException $e) {
            $this->assertSame('database exploded', $e->getMessage());
        }

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Failed, $delivery->status);
        $this->assertStringContainsString('database exploded', (string) $delivery->error);
        $this->assertStringContainsString('RuntimeException', (string) $delivery->error);
        $this->assertNull($delivery->processed_at);
    }

    public function test_a_retry_of_a_failed_delivery_can_succeed_and_clears_the_error(): void
    {
        CountingHandler::$throw = new RuntimeException('transient');
        $delivery = $this->delivery();
        try {
            $this->process($delivery);
        } catch (RuntimeException) {
        }

        CountingHandler::$throw = null;
        $this->process($delivery);

        $delivery->refresh();
        $this->assertSame(DeliveryStatus::Processed, $delivery->status);
        $this->assertNull($delivery->error);
        $this->assertSame(2, CountingHandler::$calls);
    }

    public function test_long_error_messages_are_truncated(): void
    {
        CountingHandler::$throw = new RuntimeException(str_repeat('x', 5000));
        $delivery = $this->delivery();

        try {
            $this->process($delivery);
        } catch (RuntimeException) {
        }

        $this->assertLessThanOrEqual(1000, strlen((string) $delivery->fresh()->error));
    }

    public function test_only_one_worker_can_claim_a_delivery(): void
    {
        $done = $this->delivery(status: DeliveryStatus::Processed);
        $busy = $this->delivery(status: DeliveryStatus::Processing);
        $ignored = $this->delivery(status: DeliveryStatus::Ignored);

        foreach ([$done, $busy, $ignored] as $delivery) {
            $this->process($delivery);
        }

        $this->assertSame(0, CountingHandler::$calls, 'finished or in-flight deliveries must not run again');
        $this->assertSame(DeliveryStatus::Processing, $busy->fresh()->status);
    }

    public function test_dispatching_the_same_delivery_twice_runs_the_handler_once(): void
    {
        $delivery = $this->delivery();

        $this->process($delivery);
        $this->process($delivery);

        $this->assertSame(1, CountingHandler::$calls);
    }

    public function test_a_rate_limit_puts_the_delivery_back_and_releases_the_job_with_the_delay(): void
    {
        CountingHandler::$throw = new GithubRateLimited('slow down', 90);
        $delivery = $this->delivery();

        $job = $this->process($delivery);

        $this->assertSame(DeliveryStatus::Received, $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->error);
        $job->assertReleased(delay: 90);
    }

    public function test_the_failed_hook_never_leaves_a_delivery_stuck_in_processing(): void
    {
        $stuck = $this->delivery(status: DeliveryStatus::Processing);
        $finished = $this->delivery(status: DeliveryStatus::Processed);

        (new ProcessWebhookDelivery($stuck->id))->failed(new RuntimeException('killed after timeout'));
        (new ProcessWebhookDelivery($finished->id))->failed(new RuntimeException('late'));
        (new ProcessWebhookDelivery('00000000-0000-0000-0000-000000000000'))->failed(new RuntimeException('gone'));

        $this->assertSame(DeliveryStatus::Failed, $stuck->fresh()->status);
        $this->assertStringContainsString('killed after timeout', (string) $stuck->fresh()->error);
        $this->assertSame(DeliveryStatus::Processed, $finished->fresh()->status);
    }

    public function test_retry_settings(): void
    {
        $job = new ProcessWebhookDelivery('x');

        $this->assertSame(3, $job->tries);
        $this->assertSame([10, 60, 300], $job->backoff());
    }

    public function test_only_real_handlers_can_be_registered(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(WebhookHandlers::class)->register('x', stdClass::class);
    }
}
