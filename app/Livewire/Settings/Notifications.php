<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Domain\Access\Audit;
use App\Domain\Notifications\Alerts\AlertType;
use App\Domain\Notifications\Models\Alert;
use App\Domain\Notifications\Models\NotificationRule;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Where alerts are sent besides the bell. Administrators only. */
#[Layout('components.layouts.app')]
#[Title('Notifications')]
final class Notifications extends Component
{
    public string $channel = 'mail';

    public string $target = '';

    /** '' = every alert. */
    public string $alertType = '';

    /** Signing secret of a webhook rule, shown exactly once after it is created. */
    public ?string $newSecret = null;

    public function add(): void
    {
        Gate::authorize('admin');
        $this->validate([
            'channel' => ['required', Rule::in([NotificationRule::MAIL, NotificationRule::WEBHOOK])],
            'target' => $this->channel === NotificationRule::MAIL ? ['required', 'email', 'max:255'] : ['required', 'url:http,https', 'max:500'],
            'alertType' => ['nullable', Rule::enum(AlertType::class)],
        ], [], ['target' => $this->channel === NotificationRule::MAIL ? 'e-mail address' : 'URL', 'alertType' => 'alert']);

        $secret = $this->channel === NotificationRule::WEBHOOK ? Str::random(40) : null;
        $rule = NotificationRule::query()->create([
            'channel' => $this->channel, 'target' => $this->target, 'secret' => $secret,
            'alert_type' => $this->alertType === '' ? null : $this->alertType,
        ]);
        Audit::record('notification_rule.created', $rule, ['channel' => $rule->channel, 'target' => $rule->target, 'alert_type' => $rule->alert_type]);

        $this->newSecret = $secret;
        $this->reset('target', 'alertType');
    }

    public function toggle(string $id): void
    {
        Gate::authorize('admin');
        $rule = NotificationRule::query()->findOrFail($id);
        $rule->forceFill(['enabled' => ! $rule->enabled])->save();
        Audit::record($rule->enabled ? 'notification_rule.enabled' : 'notification_rule.disabled', $rule, ['target' => $rule->target]);
    }

    public function delete(string $id): void
    {
        Gate::authorize('admin');
        $rule = NotificationRule::query()->findOrFail($id);
        $rule->delete();
        Audit::record('notification_rule.deleted', $rule, ['channel' => $rule->channel, 'target' => $rule->target]);
    }

    public function dismissSecret(): void
    {
        $this->newSecret = null;
    }

    public function render(): View
    {
        Gate::authorize('admin');

        return view('livewire.settings.notifications', [
            'rules' => NotificationRule::query()->orderBy('created_at')->get(),
            'types' => AlertType::cases(),
            'firing' => Alert::query()->where('status', Alert::FIRING)->orderByDesc('fired_at')->get(),
        ]);
    }
}
