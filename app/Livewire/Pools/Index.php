<?php

declare(strict_types=1);

namespace App\Livewire\Pools;

use App\Domain\Github\Models\Installation;
use App\Domain\Pools\Actions\PausePool;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Models\Runner;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Pools')]
final class Index extends Component
{
    #[On('echo-private:runners,.runner.state-changed')]
    public function refreshRunners(): void
    {
        // The re-render is the point.
    }

    public function pause(string $id, PausePool $pause): void
    {
        $pause($this->pool($id));
    }

    public function resume(string $id, PausePool $pause): void
    {
        $pause->resume($this->pool($id));
    }

    private function pool(string $id): RunnerPool
    {
        $pool = RunnerPool::query()->visibleTo($this->user())->findOrFail($id);
        $this->authorize('update', $pool);

        return $pool;
    }

    public function render(): View
    {
        $user = $this->user();

        return view('livewire.pools.index', [
            'pools' => RunnerPool::query()->visibleTo($user)->with('installation')->orderBy('name')->get(),
            'counts' => $this->counts(),
            'canCreate' => Installation::query()->visibleTo($user)->get()->contains(fn (Installation $i) => $user->can('createIn', [RunnerPool::class, $i])),
        ]);
    }

    /**
     * Live runners per pool and state.
     *
     * @return array<string, array<string, int>>
     */
    private function counts(): array
    {
        $counts = [];
        $rows = Runner::query()->live()->selectRaw('pool_id, state, count(*) as n')->groupBy('pool_id', 'state')->toBase()->get();
        foreach ($rows as $row) {
            $counts[$row->pool_id][$row->state] = (int) $row->n;
        }

        return $counts;
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
