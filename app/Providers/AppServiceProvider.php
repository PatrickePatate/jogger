<?php

namespace App\Providers;

use App\Domain\Access\Models\PersonalAccessToken;
use App\Domain\Github\Contracts\GithubGateway;
use App\Domain\Github\Gateway\HttpGithubGateway;
use App\Domain\Github\Gateway\RateLimitTracker;
use App\Domain\Github\Handlers\HandleInstallation;
use App\Domain\Github\Handlers\HandleInstallationRepositories;
use App\Domain\Github\Handlers\HandleRepository;
use App\Domain\Github\Handlers\HandleWorkflowJob;
use App\Domain\Github\OAuth\GithubIdentityProvider;
use App\Domain\Github\OAuth\GithubOAuth;
use App\Domain\Github\Webhooks\HandlePing;
use App\Domain\Github\Webhooks\WebhookHandlers;
use App\Domain\Hosts\Contracts\HostDriver;
use App\Domain\Hosts\Drivers\AgentHostDriver;
use App\Domain\Jobs\Logs\NoSecrets;
use App\Domain\Jobs\Logs\SecretSource;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Pools\Policies\RunnerPoolPolicy;
use App\Domain\Runners\Contracts\RunnerCensus;
use App\Domain\Runners\Contracts\RunnerEvents;
use App\Domain\Runners\Support\LogRunnerEvents;
use App\Domain\Runners\Support\NoRunners;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(HostDriver::class, AgentHostDriver::class);
        // Tests replace this with FakeGithubGateway::install().
        $this->app->bind(GithubGateway::class, HttpGithubGateway::class);

        // Placeholders until the runner state machine (Task 31) and secrets (Phase 8) exist.
        $this->app->bind(RunnerEvents::class, LogRunnerEvents::class);
        $this->app->bind(RunnerCensus::class, NoRunners::class);
        $this->app->bind(SecretSource::class, NoSecrets::class);

        $this->app->singleton(RateLimitTracker::class);
        $this->app->bind(GithubIdentityProvider::class, GithubOAuth::class);

        // Event name => handler. Later tasks add workflow_job, installation, ...
        $this->app->singleton(WebhookHandlers::class, function () {
            $handlers = new WebhookHandlers;
            $handlers->register('ping', HandlePing::class);
            $handlers->register('installation', HandleInstallation::class);
            $handlers->register('installation_repositories', HandleInstallationRepositories::class);
            $handlers->register('repository', HandleRepository::class);
            $handlers->register('workflow_job', HandleWorkflowJob::class);

            return $handlers;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Password::defaults(fn () => Password::min(10));

        // Global administrators: Gate::allows('admin'), `can:admin` middleware.
        Gate::define('admin', fn ($user) => $user->isAdmin());
        Gate::policy(RunnerPool::class, RunnerPoolPolicy::class);

        // 120 requests/minute per API token (per IP when unauthenticated).
        RateLimiter::for('api', function (Request $request) {
            $token = $request->attributes->get('access_token');

            return Limit::perMinute(120)->by($token instanceof PersonalAccessToken ? $token->id : (string) $request->ip());
        });
    }
}
