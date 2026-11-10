<div class="relative" x-data="{ open: false }" x-on:keydown.escape.window="open = false" x-on:click.outside="open = false" wire:poll.60s>
    <button type="button" class="relative rounded-control p-1.5 text-text-secondary hover:bg-ink/5" aria-haspopup="true" x-bind:aria-expanded="open"
            aria-label="Notifications{{ $unread ? ', '.$unread.' unread' : '' }}"
            x-on:click="open = ! open; if (open) { $wire.markRead() }">
        <x-icon name="info" :size="18" />
        @if ($unread)
            <span class="absolute -right-0.5 -top-0.5 grid min-w-4 place-items-center rounded-full bg-error px-1 text-[0.625rem] font-semibold leading-4 text-surface" data-testid="unread-count">{{ $unread > 9 ? '9+' : $unread }}</span>
        @endif
    </button>
    <div x-show="open" x-cloak x-transition.opacity.duration.150ms
         class="absolute right-0 z-40 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-card border border-border bg-surface shadow-pop">
        <p class="border-b border-border px-4 py-2.5 font-semibold">Notifications</p>
        <ul class="max-h-96 divide-y divide-border overflow-y-auto">
            @forelse ($notifications as $notification)
                <li class="px-4 py-3" wire:key="notification-{{ $notification->id }}">
                    <p @class(['flex items-start gap-2 font-medium', 'text-success' => $notification->kind === 'resolved'])>
                        @if ($readAt === null || $notification->created_at->gt($readAt))<span class="mt-1.5 size-1.5 shrink-0 rounded-full bg-info" aria-label="Unread"></span>@endif
                        <span>{{ $notification->title }}</span>
                    </p>
                    @if ($notification->body)<p class="mt-0.5 text-[0.8125rem] text-text-secondary">{{ $notification->body }}</p>@endif
                    @if ($notification->delivery_errors)
                        <p class="mt-1 text-[0.8125rem] text-error">Could not be sent by {{ collect($notification->delivery_errors)->pluck('channel')->unique()->implode(' or ') }}; shown here only.</p>
                    @endif
                    <p class="mt-1 text-[0.75rem] text-text-secondary">{{ $notification->created_at->diffForHumans() }}</p>
                </li>
            @empty
                <li class="px-4 py-6 text-center text-text-secondary">Nothing to report.</li>
            @endforelse
        </ul>
        <a href="{{ route('settings.notifications') }}" wire:navigate class="block border-t border-border px-4 py-2.5 text-[0.8125rem] font-medium hover:bg-ink/5">Notification settings</a>
    </div>
</div>
