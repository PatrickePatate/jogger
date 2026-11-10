<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Channels;

use App\Domain\Notifications\Models\Alert;
use App\Domain\Notifications\Models\Notification;
use App\Domain\Notifications\Models\NotificationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Announces an alert: always in the app, then through every enabled rule. A channel that fails never stops the
 * others; its error is kept on the rule and on the in-app notice, which is then the only place the alert shows.
 */
final class Dispatcher
{
    public const string SIGNATURE_HEADER = 'X-Jogger-Signature-256';

    public function send(Alert $alert, string $kind): Notification
    {
        $resolved = $kind === Notification::RESOLVED;
        $title = $resolved ? "Resolved: {$alert->title}" : $alert->title;
        $body = $resolved ? 'The condition has cleared.' : (string) $alert->body;

        $errors = [];
        $rules = NotificationRule::query()->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('alert_type')->orWhere('alert_type', $alert->type))->get();
        foreach ($rules as $rule) {
            try {
                $rule->channel === NotificationRule::MAIL
                    ? $this->mail($rule, $title, $body)
                    : $this->webhook($rule, $alert, $kind, $title, $body);
                $rule->forceFill(['last_sent_at' => now(), 'last_error' => null, 'last_error_at' => null])->save();
            } catch (Throwable $e) {
                $message = Str::limit($e->getMessage(), 300);
                $rule->forceFill(['last_error' => $message, 'last_error_at' => now()])->save();
                $errors[] = ['channel' => $rule->channel, 'target' => $rule->target, 'error' => $message];
                Log::warning('Alert notification failed', ['channel' => $rule->channel, 'rule' => $rule->id, 'error' => $message]);
            }
        }

        return Notification::query()->create([
            'alert_id' => $alert->id, 'type' => $alert->type, 'kind' => $kind, 'title' => $title, 'body' => $body,
            'delivery_errors' => $errors === [] ? null : $errors, 'created_at' => now(),
        ]);
    }

    private function mail(NotificationRule $rule, string $title, string $body): void
    {
        $text = $body."\n\n".url('/');
        Mail::raw($text, fn ($message) => $message->to($rule->target)->subject('[Jogger] '.$title));
    }

    private function webhook(NotificationRule $rule, Alert $alert, string $kind, string $title, string $body): void
    {
        $payload = (string) json_encode([
            'event' => "alert.{$kind}",
            'alert' => ['id' => $alert->id, 'type' => $alert->type, 'subject' => $alert->subject, 'title' => $title, 'body' => $body,
                'fired_at' => $alert->fired_at->toIso8601String(), 'resolved_at' => $alert->resolved_at?->toIso8601String()],
            'sent_at' => now()->toIso8601String(),
        ]);

        // Same scheme as GitHub's own webhooks, so receivers can reuse their verification code.
        Http::timeout(5)->withHeaders([
            self::SIGNATURE_HEADER => 'sha256='.hash_hmac('sha256', $payload, (string) $rule->secret),
            'User-Agent' => 'Jogger-Alerts',
        ])->withBody($payload, 'application/json')->post($rule->target)->throw();
    }
}
