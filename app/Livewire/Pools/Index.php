<?php

declare(strict_types=1);

namespace App\Livewire\Pools;

use App\Domain\Github\Models\Installation;
use App\Domain\Pools\Actions\PausePool;
use App\Domain\Pools\Models\RunnerPool;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('Pools')]
final class Index extends Component
{
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
            // Filled once runners exist (Task 31).
            'counts' => [],
            'canCreate' => Installation::query()->visibleTo($user)->get()->contains(fn (Installation $i) => $user->can('createIn', [RunnerPool::class, $i])),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
