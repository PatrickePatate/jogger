{{-- Validation summary for plain (non-Livewire) forms. --}}
@if ($errors->any())
    <div class="flex items-start gap-2 rounded-control bg-danger/10 px-3 py-2 text-[0.8125rem] text-danger-ink" role="alert">
        <x-icon name="x-circle" :size="16" class="mt-0.5" />
        <ul class="space-y-0.5">
            @foreach (collect($errors->all())->unique() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif
