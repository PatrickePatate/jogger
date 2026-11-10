<?php

declare(strict_types=1);

namespace App\Livewire\Shared;

use App\Domain\Notifications\Models\Notification;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** The in-app channel: recent alerts for administrators, with a count of those not seen yet. */
final class NotificationBell extends Component
{
    public function markRead(): void
    {
        $this->user()->forceFill(['notifications_read_at' => now()])->save();
    }

    public function render(): View
    {
        Gate::authorize('admin');
        $readAt = $this->user()->notifications_read_at;

        return view('livewire.shared.notification-bell', [
            'notifications' => Notification::query()->orderByDesc('created_at')->orderByDesc('id')->limit(10)->get(),
            'unread' => Notification::query()->when($readAt !== null, fn ($q) => $q->where('created_at', '>', $readAt))->count(),
            'readAt' => $readAt,
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
