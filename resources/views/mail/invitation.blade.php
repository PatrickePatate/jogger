<x-mail::message>
# You're invited to {{ config('app.name') }}

@if ($inviter)
{{ $inviter }} invited you to {{ config('app.name') }}, a self-hosted platform for running GitHub Actions jobs on your own hardware.
@else
You have been invited to {{ config('app.name') }}, a self-hosted platform for running GitHub Actions jobs on your own hardware.
@endif

@if ($administrator)
You will be an **administrator**.
@elseif ($teamName)
You will join the **{{ $teamName }}** team as **{{ $role }}**.
@endif

<x-mail::button :url="$url">
Accept invitation
</x-mail::button>

This link works for {{ $days }} days and can be used once. If you weren't expecting it, you can ignore this email.
</x-mail::message>
