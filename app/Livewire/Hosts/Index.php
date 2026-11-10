<?php

declare(strict_types=1);

namespace App\Livewire\Hosts;

use App\Domain\Hosts\Actions\CordonHost;
use App\Domain\Hosts\Actions\DrainHost;
use App\Domain\Hosts\Actions\RevokeHost;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
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

    public function cordon(string $id, CordonHost $cordon): void
    {
        $cordon($this->host($id));
    }

    public function uncordon(string $id, CordonHost $cordon): void
    {
        $cordon->uncordon($this->host($id));
    }

    public function drain(string $id, DrainHost $drain): void
    {
        $drain($this->host($id));
    }

    public function revoke(string $id, RevokeHost $revoke): void
    {
        $revoke($this->host($id));
    }

    private function host(string $id): Host
    {
        Gate::authorize('admin');

        return Host::query()->findOrFail($id);
    }

    public function render(): View
    {
        return view('livewire.hosts.index', [
            // Revoked hosts are history, not infrastructure.
            'hosts' => Host::query()->where('status', '!=', HostStatus::Revoked->value)->orderBy('name')->get(),
        ]);
    }
}
