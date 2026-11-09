<?php

declare(strict_types=1);

namespace App\Livewire\Hosts;

use App\Domain\Hosts\Actions\CreateEnrollmentToken;
use App\Domain\Hosts\Models\EnrollmentToken;
use App\Domain\Hosts\Models\Host;
use App\Models\User;
use App\Support\Ui\StepData;
use App\Support\Ui\StepState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/** The "Add a host" wizard: create a one-time token, run the command on the machine, watch the agent connect. */
final class EnrollModal extends Component
{
    public string $hostName = '';

    /** The plain token, kept only while this dialog is open; it is never stored. */
    public ?string $plainToken = null;

    public ?string $tokenId = null;

    public function create(CreateEnrollmentToken $create): void
    {
        Gate::authorize('admin');

        $this->validate(['hostName' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/']]);

        /** @var User $user */
        $user = Auth::user();
        [$this->plainToken, $token] = $create($user, trim($this->hostName) ?: null);
        $this->tokenId = $token->id;
    }

    public function resetWizard(): void
    {
        $this->reset('hostName', 'plainToken', 'tokenId');
        $this->resetValidation();
    }

    /** The docker run command a person pastes on the new machine. */
    public function command(): string
    {
        $server = rtrim((string) config('app.url'), '/');

        return "docker run -d --name jogger-agent --restart unless-stopped \\\n"
            ."  -v /var/run/docker.sock:/var/run/docker.sock \\\n"
            ."  -v jogger-agent-data:/var/lib/jogger-agent \\\n"
            ."  -e JOGGER_SERVER={$server} \\\n"
            ."  -e JOGGER_ENROLL_TOKEN={$this->plainToken} \\\n"
            .'  ghcr.io/jogger/jogger-agent:latest';
    }

    /**
     * @return array<int, StepData>
     */
    private function steps(?EnrollmentToken $token, ?Host $host): array
    {
        $created = $token !== null;
        $connected = $host !== null;
        $detected = $host !== null && $host->cpu_total !== null && $host->last_seen_at !== null;

        $state = fn (bool $done, bool $active) => $done ? StepState::Done : ($active ? StepState::Active : StepState::Pending);

        return [
            new StepData('Create a token', $created ? 'Valid for 1 hour, usable once' : null, $state($created, ! $created)),
            new StepData('Run the command', $connected ? null : ($created ? 'On the machine that will run runners' : null), $state($connected, $created && ! $connected)),
            new StepData('Agent connected', $connected ? $host->name : null, $state($connected, false)),
            new StepData('Capacity detected', $detected ? "{$host->cpu_total} CPUs · ".round($host->memory_total_mb / 1024, 1).' GB memory' : null, $state($detected, $connected && ! $detected)),
        ];
    }

    public function render(): View
    {
        $token = $this->tokenId ? EnrollmentToken::query()->with('usedByHost')->find($this->tokenId) : null;
        $host = $token?->usedByHost;

        return view('livewire.hosts.enroll-modal', [
            'steps' => $this->steps($token, $host),
            'command' => $this->plainToken ? $this->command() : null,
            'expired' => $token !== null && $token->used_at === null && $token->expires_at->isPast(),
            'host' => $host,
        ]);
    }
}
