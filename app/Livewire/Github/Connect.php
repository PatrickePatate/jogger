<?php

declare(strict_types=1);

namespace App\Livewire\Github;

use App\Domain\Github\Actions\ConnectGithubAppManually;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Support\WebhookReachability;
use App\Models\User;
use App\Support\Exceptions\JoggerException;
use App\Support\Ui\StepData;
use App\Support\Ui\StepState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('GitHub')]
final class Connect extends Component
{
    public string $appId = '';

    public string $clientId = '';

    public string $clientSecret = '';

    public string $webhookSecret = '';

    public string $privateKey = '';

    public function connectManually(ConnectGithubAppManually $connect): void
    {
        Gate::authorize('admin');

        $this->validate([
            'appId' => ['required', 'integer', 'min:1'],
            'clientId' => ['required', 'string', 'max:64'],
            'clientSecret' => ['required', 'string', 'max:255'],
            'webhookSecret' => ['required', 'string', 'max:255'],
            'privateKey' => ['required', 'string', 'max:10000'],
        ]);

        try {
            /** @var User $admin */
            $admin = Auth::user();
            $app = $connect($admin, [
                'app_id' => $this->appId,
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'webhook_secret' => $this->webhookSecret,
                'private_key' => $this->privateKey,
            ]);
        } catch (JoggerException $e) {
            $this->addError('manual', $e->getMessage());

            return;
        }

        $this->reset('appId', 'clientId', 'clientSecret', 'webhookSecret', 'privateKey');
        $this->dispatch('toast', type: 'success', message: "GitHub App “{$app->slug}” connected.");
    }

    public function render(): View
    {
        $app = GithubApp::query()->first();
        $installations = $app ? Installation::query()->withCount('repositories')->orderBy('account_login')->get() : collect();
        $repositories = (int) $installations->sum('repositories_count');

        $webBase = rtrim((string) config('jogger.github.web_url'), '/');

        return view('livewire.github.connect', [
            'app' => $app,
            'installations' => $installations,
            'reachability' => WebhookReachability::check(),
            'webhookUrl' => rtrim((string) config('app.url'), '/').'/webhooks/github',
            'installUrl' => $app ? "{$webBase}/apps/{$app->slug}/installations/new" : null,
            'appUrl' => $app ? "{$webBase}/apps/{$app->slug}" : null,
            'steps' => $this->steps($app, $installations->count(), $repositories),
        ]);
    }

    /**
     * @return list<StepData>
     */
    private function steps(?GithubApp $app, int $installations, int $repositories): array
    {
        return [
            new StepData('Create the GitHub App', $app?->slug, $app ? StepState::Done : StepState::Active),
            new StepData(
                'Install it on your account',
                $installations > 0 ? trans_choice(':count installation|:count installations', $installations) : ($app ? 'Waiting for GitHub…' : null),
                match (true) {
                    $installations > 0 => StepState::Done,
                    $app !== null => StepState::Active,
                    default => StepState::Pending,
                },
            ),
            new StepData(
                'Pick repositories',
                $repositories > 0 ? trans_choice(':count repository|:count repositories', $repositories) : ($installations > 0 ? 'Choose repositories when installing' : null),
                match (true) {
                    $repositories > 0 => StepState::Done,
                    $installations > 0 => StepState::Active,
                    default => StepState::Pending,
                },
            ),
        ];
    }
}
