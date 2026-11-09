<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Ui\StepData;
use App\Support\Ui\StepState;
use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StepperRenderTest extends TestCase
{
    /**
     * @param  array<int, array<string, mixed>|StepData>  $steps
     */
    private function stepper(array $steps, string $title = 'Job'): string
    {
        return Blade::render('<x-ui.stepper :title="$title" :steps="$steps" />', compact('title', 'steps'));
    }

    public function test_two_of_four_done_shows_counter_and_half_bar(): void
    {
        $html = $this->stepper([
            ['label' => 'A', 'state' => 'done'],
            ['label' => 'B', 'state' => 'done'],
            ['label' => 'C', 'state' => 'active'],
            ['label' => 'D', 'state' => 'pending'],
        ]);

        $this->assertStringContainsString('2 of 4', $html);
        $this->assertStringContainsString('width: 50%', $html);
        $this->assertStringContainsString('aria-valuenow="2"', $html);
        $this->assertStringContainsString('aria-valuemax="4"', $html);
    }

    public function test_each_state_has_its_own_screen_reader_label_and_marker(): void
    {
        $html = $this->stepper(array_map(
            fn (StepState $s) => ['label' => $s->name, 'state' => $s->value],
            StepState::cases(),
        ));

        foreach (['Done', 'In progress', 'Pending', 'Failed', 'Skipped'] as $label) {
            $this->assertStringContainsString("— {$label}", $html);
        }
        $this->assertStringContainsString('bg-success', $html);          // done node
        $this->assertStringContainsString('bg-error', $html);            // failed node
        $this->assertStringContainsString('animate-spin', $html);        // active arc
        $this->assertStringContainsString('border-dashed', $html);       // skipped
        $this->assertStringContainsString('aria-current="step"', $html); // active step announced
    }

    public function test_spinner_is_disabled_for_reduced_motion(): void
    {
        $html = $this->stepper([['label' => 'Run', 'state' => 'active']]);

        $this->assertStringContainsString('motion-reduce:animate-none', $html);
    }

    public function test_connector_after_done_step_is_green_otherwise_neutral(): void
    {
        $html = $this->stepper([
            ['label' => 'A', 'state' => 'done'],
            ['label' => 'B', 'state' => 'pending'],
            ['label' => 'C', 'state' => 'pending'],
        ]);

        $this->assertSame(1, substr_count($html, 'w-0.5 flex-1 min-h-4 rounded-full bg-success'));
        $this->assertSame(1, substr_count($html, 'w-0.5 flex-1 min-h-4 rounded-full bg-border-strong'));
    }

    public function test_accepts_step_data_objects_and_shows_detail_lines(): void
    {
        $html = $this->stepper([new StepData('Profile the frame', '0.24 ms', StepState::Done)]);

        $this->assertStringContainsString('Profile the frame', $html);
        $this->assertStringContainsString('0.24 ms', $html);
        $this->assertStringContainsString('1 of 1', $html);
    }

    public function test_empty_steps_render_without_dividing_by_zero(): void
    {
        $this->assertStringContainsString('0 of 0', $this->stepper([]));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pills(): array
    {
        return [
            'job queued' => ['<x-job-status-pill status="queued" />', 'Queued'],
            'job running' => ['<x-job-status-pill status="in_progress" />', 'Running'],
            'job success' => ['<x-job-status-pill status="completed" conclusion="success" />', 'Succeeded'],
            'job failure' => ['<x-job-status-pill status="completed" conclusion="failure" />', 'Failed'],
            'job cancelled' => ['<x-job-status-pill status="completed" conclusion="cancelled" />', 'Cancelled'],
            'runner provisioning' => ['<x-runner-state-pill state="provisioning" />', 'Provisioning'],
            'runner idle' => ['<x-runner-state-pill state="idle" />', 'Idle'],
            'runner busy' => ['<x-runner-state-pill state="busy" />', 'Busy'],
            'runner terminating' => ['<x-runner-state-pill state="terminating" />', 'Terminating'],
            'runner gone' => ['<x-runner-state-pill state="gone" />', 'Gone'],
            'runner failed' => ['<x-runner-state-pill state="failed" />', 'Failed'],
            'host pending' => ['<x-host-status-pill status="pending" />', 'Pending'],
            'host online' => ['<x-host-status-pill status="online" />', 'Online'],
            'host offline' => ['<x-host-status-pill status="offline" />', 'Offline'],
            'host cordoned' => ['<x-host-status-pill status="cordoned" />', 'Cordoned'],
            'host draining' => ['<x-host-status-pill status="draining" />', 'Draining'],
            'host revoked' => ['<x-host-status-pill status="revoked" />', 'Revoked'],
        ];
    }

    #[DataProvider('pills')]
    public function test_pills_show_a_text_label_and_icon(string $template, string $label): void
    {
        $html = Blade::render($template);

        $this->assertStringContainsString($label, $html);
        $this->assertStringContainsString('<svg', $html); // never color alone
    }

    public function test_unknown_value_renders_neutral_with_raw_label(): void
    {
        $html = Blade::render('<x-host-status-pill status="mystery_state" />');

        $this->assertStringContainsString('Mystery State', $html);
        $this->assertStringContainsString('text-text-secondary', $html);
    }

    public function test_pool_meter_summarizes_for_assistive_tech(): void
    {
        $html = Blade::render('<x-pool-meter :busy="3" :idle="2" :provisioning="1" :max="10" />');

        $this->assertStringContainsString('3 busy, 2 idle, 1 provisioning of 10 maximum', $html);
        $this->assertStringContainsString('width: 30%', $html);
        $this->assertStringContainsString('6</span> / 10 runners', $html);
    }
}
