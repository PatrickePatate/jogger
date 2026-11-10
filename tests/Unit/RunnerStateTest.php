<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Runners\Enums\RunnerState;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RunnerStateTest extends TestCase
{
    /** The transition table of IMPLEMENTATION.md §2.2, written out independently of the enum. */
    private const ALLOWED = [
        'provisioning' => ['idle', 'busy', 'failed', 'terminating'],
        'idle' => ['busy', 'terminating', 'failed'],
        'busy' => ['terminating', 'failed'],
        'terminating' => ['gone', 'failed'],
        'gone' => [],
        'failed' => [],
    ];

    /**
     * @return iterable<string, array{RunnerState, RunnerState, bool}>
     */
    public static function pairs(): iterable
    {
        foreach (RunnerState::cases() as $from) {
            foreach (RunnerState::cases() as $to) {
                yield "{$from->value} -> {$to->value}" => [$from, $to, in_array($to->value, self::ALLOWED[$from->value], true)];
            }
        }
    }

    #[DataProvider('pairs')]
    public function test_transition_table(RunnerState $from, RunnerState $to, bool $allowed): void
    {
        $this->assertSame($allowed, $from->canBecome($to));
        $this->assertSame($allowed, in_array($from, $to->previous(), true));
    }

    public function test_classification(): void
    {
        $this->assertTrue(RunnerState::Busy->isLive());
        $this->assertFalse(RunnerState::Terminating->isLive());
        $this->assertTrue(RunnerState::Failed->isTerminal());
        $this->assertSame([], RunnerState::Gone->next());
    }
}
