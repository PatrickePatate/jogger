<?php

declare(strict_types=1);

namespace App\Livewire\Hosts;

use App\Domain\Hosts\Actions\CordonHost;
use App\Domain\Hosts\Actions\DrainHost;
use App\Domain\Hosts\Actions\RequestAgentUpdate;
use App\Domain\Hosts\Actions\RevokeHost;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\Host;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
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
    #[On('echo-private:runners,.runner.state-changed')]
    public function refreshHosts(): void
    {
        // The re-render is the point.
    }

    /** Host whose runners are listed. */
    public ?string $selected = null;

    public function select(string $id): void
    {
        $this->selected = $this->selected === $id ? null : $id;
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

    public function updateAgent(string $id, RequestAgentUpdate $update): void
    {
        $update($this->host($id));
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
            'hosts' => Host::query()->with('template:id,name')->where('status', '!=', HostStatus::Revoked->value)->orderBy('name')->get(),
            'counts' => $this->counts(),
            'runners' => $this->selected === null ? collect() : Runner::query()->where('host_id', $this->selected)
                ->inState(...RunnerState::present())->with(['pool', 'job'])->orderByDesc('id')->limit(100)->get(),
        ]);
    }

    /**
     * Runners with a container on each host, by state.
     *
     * @return array<string, array<string, int>>
     */
    private function counts(): array
    {
        $counts = [];
        $rows = Runner::query()->inState(...RunnerState::present())->whereNotNull('host_id')
            ->selectRaw('host_id, state, count(*) as n')->groupBy('host_id', 'state')->toBase()->get();
        foreach ($rows as $row) {
            $counts[$row->host_id][$row->state] = (int) $row->n;
        }

        return $counts;
    }
}
