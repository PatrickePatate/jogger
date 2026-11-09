<?php

declare(strict_types=1);

namespace App\Livewire\Hosts;

use App\Domain\Hosts\Models\Host;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Hosts')]
final class Index extends Component
{
    /** Re-render when any host changes status (the heartbeat poll below covers capacity numbers). */
    #[On('echo-private:hosts,.host.status-changed')]
    public function refreshHosts(): void
    {
        // The re-render is the point.
    }

    public function render(): View
    {
        return view('livewire.hosts.index', [
            'hosts' => Host::query()->orderBy('name')->get(),
        ]);
    }
}
