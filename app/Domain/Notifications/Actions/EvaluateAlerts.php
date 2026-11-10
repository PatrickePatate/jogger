<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Alerts\DetectConditions;
use App\Domain\Notifications\Channels\Dispatcher;
use App\Domain\Notifications\Models\Alert;
use App\Domain\Notifications\Models\Notification;

/**
 * Compares what is wrong now with the alerts already open: new problems fire, cleared ones resolve.
 * An alert that fires again within the dedupe window of its last notice stays quiet (and so does its end).
 */
final class EvaluateAlerts
{
    public function __construct(private readonly DetectConditions $detect, private readonly Dispatcher $dispatcher) {}

    /**
     * @return array{fired: int, resolved: int}
     */
    public function __invoke(): array
    {
        $window = now()->subMinutes(max(0, (int) config('jogger.alerts.dedupe_minutes')));
        $current = [];
        $fired = $resolved = 0;

        foreach (($this->detect)() as $condition) {
            $key = $condition->type->value.'|'.$condition->subject;
            $current[$key] = true;

            $alert = Alert::query()->firstOrNew(['type' => $condition->type->value, 'subject' => $condition->subject]);
            if ($alert->exists && $alert->status === Alert::FIRING) {
                continue; // already known
            }
            $notify = $alert->notified_at === null || $alert->notified_at->lte($window);
            $alert->fill([
                'status' => Alert::FIRING, 'title' => $condition->title, 'body' => $condition->body,
                'fired_at' => now(), 'resolved_at' => null, 'episode_notified' => $notify,
            ]);
            if ($notify) {
                $alert->notified_at = now();
            }
            $alert->save();
            $fired++;
            if ($notify) {
                $this->dispatcher->send($alert, Notification::FIRED);
            }
        }

        foreach (Alert::query()->where('status', Alert::FIRING)->get() as $alert) {
            if (isset($current[$alert->type.'|'.$alert->subject])) {
                continue;
            }
            $alert->forceFill(['status' => Alert::RESOLVED, 'resolved_at' => now()])->save();
            $resolved++;
            if ($alert->episode_notified) {
                $this->dispatcher->send($alert, Notification::RESOLVED);
            }
        }

        return ['fired' => $fired, 'resolved' => $resolved];
    }
}
