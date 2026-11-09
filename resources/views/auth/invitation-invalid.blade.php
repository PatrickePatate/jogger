<x-layouts.guest title="Invitation unavailable">
    <h1 class="text-lg font-semibold">This invitation link doesn't work</h1>
    <p class="mt-1 text-text-secondary">It may have expired or been changed. Ask an administrator to send you a new invitation.</p>
    <div class="mt-5"><x-ui.button href="{{ route('login') }}" variant="outline">Go to sign in</x-ui.button></div>
</x-layouts.guest>
