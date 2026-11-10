<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Domain\Access\Audit;
use App\Domain\Settings\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Instance-wide settings. Administrators only; every change is audited. */
#[Layout('components.layouts.app')]
#[Title('Instance settings')]
final class General extends Component
{
    /** @var array<string, mixed> */
    public array $form = [];

    public function mount(Settings $settings): void
    {
        Gate::authorize('admin');
        foreach (array_keys(Settings::KEYS) as $key) {
            $this->form[$key] = $settings->get($key);
        }
    }

    public function save(Settings $settings): void
    {
        Gate::authorize('admin');
        $rules = [];
        $names = [];
        foreach (Settings::KEYS as $key => [, $rule]) {
            $rules["form.{$key}"] = $rule;
            $names["form.{$key}"] = str_replace('_', ' ', $key);
        }
        $this->validate($rules, [], $names);

        $changed = $settings->set($this->form);
        if ($changed !== []) {
            Audit::record('settings.updated', null, ['changes' => $changed]);
        }
        $this->dispatch('toast', type: 'success', message: $changed === [] ? 'Nothing changed.' : 'Settings saved.');
    }

    public function render(): View
    {
        return view('livewire.settings.general');
    }
}
