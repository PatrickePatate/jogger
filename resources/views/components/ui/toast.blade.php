{{-- Global toast region. Trigger with toast({type, message}) in JS or $this->dispatch('toast', type: 'success', message: '…') from Livewire. --}}
<div x-data="{
        toasts: [],
        add(d) {
            const id = Date.now() + Math.random();
            this.toasts.push({ id, type: d.type || 'info', message: d.message || '' });
            setTimeout(() => this.remove(id), d.duration || 5000);
        },
        remove(id) { this.toasts = this.toasts.filter(t => t.id !== id); },
     }"
     x-on:toast.window="add($event.detail)"
     class="pointer-events-none fixed inset-x-4 bottom-4 z-[60] flex flex-col items-end gap-2 sm:inset-x-auto sm:right-4" aria-live="polite">
    <template x-for="t in toasts" :key="t.id">
        <div x-transition.duration.200ms role="status"
             class="pointer-events-auto flex w-full max-w-sm items-start gap-3 rounded-card border border-border bg-surface p-3 shadow-pop">
            <span class="mt-0.5" :class="{ 'text-success': t.type === 'success', 'text-error': t.type === 'error', 'text-warning': t.type === 'warning', 'text-info': !['success','error','warning'].includes(t.type) }">
                <template x-if="t.type === 'success'"><x-icon name="check-circle" :size="18" /></template>
                <template x-if="t.type === 'error'"><x-icon name="x-circle" :size="18" /></template>
                <template x-if="t.type === 'warning'"><x-icon name="warning" :size="18" /></template>
                <template x-if="!['success','error','warning'].includes(t.type)"><x-icon name="info" :size="18" /></template>
            </span>
            <p class="flex-1" x-text="t.message"></p>
            <button type="button" class="-m-1 rounded p-1 text-text-secondary hover:bg-ink/5" x-on:click="remove(t.id)" aria-label="Dismiss">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
    </template>
</div>
