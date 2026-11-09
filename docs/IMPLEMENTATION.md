# Jogger — Implementation Plan

> Technical blueprint derived from [SPECIFICATION.md](SPECIFICATION.md). Section references (§) point to the spec.

## 1. Tech Stack

### 1.1 Stack Summary

| Layer | Technology | Version | Rationale |
|-------|-----------|---------|-----------|
| Control plane language | PHP | 8.3+ (dev box runs 8.5) | Matches `composer.json` constraint; user-chosen Laravel stack. |
| Framework | Laravel | 13.x (skeleton installed) | Queues, scheduler, broadcasting, policies, encryption built in; covers §3.8, §3.3, §3.5. |
| UI | Livewire | 4.x (4.4.8 latest at time of writing) | Server-driven reactive UI with minimal JS; realtime via Echo hooks (§7). |
| UI behavior / CSS | Alpine.js + Tailwind CSS | Alpine 3.x (bundled with Livewire), Tailwind 4.x | Already in `package.json`; Pines UI copy-paste components are Alpine + Tailwind. |
| Component kit | Pines UI (devdojo.com/pines) | n/a (copied into repo) | Chosen by user; no runtime dependency, components live in `resources/views/components/ui`. |
| Realtime | Laravel Reverb | 1.x (1.12.0) | First-party WebSocket server, Pusher protocol, horizontally scalable via Redis; used for browsers **and** agent command push (see ADR-2). |
| Queues | Laravel Horizon | 5.x (5.50.x); re-check Laravel 13 support at install, 6.x-dev exists | Redis queue supervision, per-queue concurrency, metrics. |
| Database | PostgreSQL | 17 (16+ supported) | Relational data, `jsonb`, advisory locks, `FOR UPDATE SKIP LOCKED`, partial indexes. |
| Cache/locks/pubsub | Redis | 7+ | Queues, atomic locks, Reverb scaling, log buffering via Streams. |
| Auth | Laravel Fortify + Socialite | Fortify 1.x, Socialite 5.x | Headless login, 2FA (TOTP), password reset; Socialite for GitHub OAuth. No starter kit lock-in on the UI. |
| GitHub API client | Laravel `Http` client + `firebase/php-jwt` | latest | Thin typed gateway (Adapter) rather than a heavy SDK; need only ~12 endpoints. |
| Agent language | Go | 1.25+ (dev box has 1.26) | Static binary, tiny footprint (§10.3), first-class Docker SDK. |
| Docker access (agent) | `github.com/docker/docker/client` | latest | Official engine SDK. |
| WebSocket (agent) | `github.com/coder/websocket` | latest | Maintained, context-aware, minimal. |
| PHP testing | PHPUnit | 12.x (already installed) | Matches existing `phpunit.xml`; Livewire test helpers work with it. |
| Go testing | `go test` + `testcontainers-go` | latest | Real Docker for agent integration tests. |
| Linting | Pint (PHP), `golangci-lint` (Go), Prettier (Blade/JS optional) | Pint 1.27 | Pint already installed. |
| Static analysis | Larastan | latest, level 6 | Catch type errors in orchestrator code. |
| Build | Vite 8 + `laravel-vite-plugin` 3 | per `package.json` | Already configured. |
| CI/CD | GitHub Actions | — | Project is open source on GitHub; matrix for PHP + Go; builds multi-arch images (buildx). |
| Container | Docker + Compose | Docker 24+ | Distribution model §9.2. |
| PHP runtime image | FrankenPHP (`dunglas/frankenphp`) | latest stable | One image, several commands (web, horizon, scheduler, reverb); built-in HTTPS option. |

### 1.2 Key Technical Decisions

#### ADR-1: Modular monolith with a separate Go agent
- **Context**: §4.1 lists 7 components. Solo/small team, self-hosted install must stay `docker compose up`.
- **Options**:
  1. **Microservices**: independent scaling / operational burden, unacceptable for self-hosters.
  2. **Modular monolith (Laravel) + Go agent**: one deployable control plane with enforced module boundaries; agent is the only second codebase.
  3. **All PHP incl. agent**: single language / PHP daemon controlling Docker is awkward, larger footprint, harder to ship as a static binary.
- **Choice**: Option 2.
- **Rationale**: Control plane is I/O-bound and CRUD-heavy (Laravel excels); the agent is system-level (Go excels). One repo (monorepo: `agent/` directory).
- **Consequences**: Two toolchains in CI; the wire protocol (§ADR-2) must be versioned and tested from both sides.

#### ADR-2: Agent transport = Reverb push + HTTPS pull/POST with a DB-backed command outbox
- **Context**: §3.4.1 requires outbound-only agents; §3.3.1 requires ~15 s job start; the TBD "WebSocket vs gRPC".
- **Options**:
  1. **gRPC bidirectional stream**: typed / needs a separate server (PHP has no good native server), poor fit behind simple reverse proxies.
  2. **Custom WebSocket server in PHP**: duplicates Reverb.
  3. **Reverb (Pusher protocol) for server→agent push; HTTPS REST for agent→server**; commands persisted in `host_commands` with ack/retry.
- **Choice**: Option 3.
- **Rationale**: Reuses Reverb already deployed for the UI; HTTPS POST for events/logs works through any proxy and can be batched; the outbox makes delivery reliable (a push is only a *hint* to fetch). The agent also polls `GET /agent/v1/commands` every 15 s and on every reconnect, so lost pushes never lose work.
- **Consequences**: Agent implements a minimal Pusher client (subscribe to `private-host.{id}`, auth via `POST /agent/v1/broadcasting/auth`). Protocol versioned by `X-Jogger-Agent-Protocol` header. Resolves spec §11.4 "Agent protocol".

#### ADR-3: Ephemeral runners via GitHub JIT config
- **Context**: §3.3.1, §8.3.
- **Choice**: For every runner, call `POST /repos/{o}/{r}/actions/runners/generate-jitconfig` (or the org endpoint), pass the returned `encoded_jit_config` to the container as `JIT_CONFIG`, entrypoint runs `./run.sh --jitconfig "$JIT_CONFIG"`. No registration token is ever stored in the container image or on disk. Warm-pool runners are created the same way (idle until GitHub assigns a job).
- **Consequences**: Warm runners hold a registration that expires if unused (GitHub removes idle JIT runners after ~24 h) so `idle_ttl_seconds` is enforced by Jogger (default 15 min) and the runner is deleted in GitHub via API.

#### ADR-4: Livewire full-page components + Echo, not a SPA
- **Context**: §7, "nice UI" with real-time updates, one-person-maintainable.
- **Choice**: Livewire 4 page components; real-time through Reverb-powered Echo listeners (`echo-private:` events) that trigger lightweight component refreshes; the log terminal is an Alpine component that appends streamed chunks client-side and only asks the server for scrollback.
- **Consequences**: Log streaming bypasses Livewire's re-render cycle (otherwise too chatty); tested with browser tests (Laravel Dusk optional, see §9).

#### ADR-5: State machines as backed enums with explicit transition tables
- **Context**: Runner and Job lifecycles (§2, §3.3) have many failure paths and concurrent writers (webhook worker, agent events, reconciler).
- **Choice**: `RunnerState` and `JobStatus` enums define allowed transitions; all writes go through `TransitionRunner` / `TransitionJob` actions using optimistic locking (`UPDATE ... WHERE state = :expected`).
- **Consequences**: Illegal/late events (e.g. `completed` webhook arriving before `in_progress`) are tolerated by idempotent no-op transitions, not exceptions.

#### ADR-6: Concurrency control with a row lock per pool, not Redis-only locks
- **Context**: "Never exceed max_runners under concurrent webhooks" (§3.3.1).
- **Choice**: Provisioning runs inside a DB transaction that `SELECT ... FOR UPDATE` locks the pool row, counts live runners, inserts the new `runners` row (`provisioning`), then commits and only afterwards calls GitHub. Redis locks used only for deduplicating work (e.g. one reconciler at a time).
- **Consequences**: Correct even if Redis restarts; failures after the insert are cleaned up by the reconciler (§3.1.3).

#### ADR-7: Caching scope for v1
- **Context**: §3.7, open question on `actions/cache` backend.
- **Choice**: v1.0 ships (a) a persistent per-pool `/opt/hostedtoolcache` volume (opt-in) and (b) per-Host Docker build cache retention, which cover the largest wins. An `actions/cache`-protocol-compatible server is a **separate milestone (M9)** and is marked experimental: it requires implementing the cache service API the runner calls and redirecting via `ACTIONS_CACHE_URL`/`ACTIONS_RESULTS_URL` in the runner image; protocol details must be verified against the current `actions/cache` before committing to it.
- **Consequences**: Spec §3.7 acceptance criteria for `actions/cache` are delivered in M9, not M1–M8.

#### ADR-8: Docker-in-job via rootless DinD sidecar (opt-in)
- **Context**: §8.3, TBD in §11.4.
- **Choice**: When a pool sets `docker_mode = dind`, the agent creates a per-runner private network and a `docker:dind-rootless` sidecar; the runner gets `DOCKER_HOST=tcp://dind:2376` with TLS. Host socket is never mounted. Sysbox is documented as an advanced alternative, not implemented.
- **Consequences**: Rootless DinD requires user namespaces on the Host; agent preflight reports capability and the UI disables the option if unsupported.

### 1.3 Dependency Inventory

**Philosophy:** ecosystem standard for Laravel (first-party packages preferred), curated minimal on the Go side.

| Package | Purpose | License | Justification |
|---------|---------|---------|---------------|
| laravel/framework | Core | MIT | — |
| livewire/livewire | UI components | MIT | User-chosen; §7. |
| laravel/reverb | WebSockets | MIT | Browser + agent push. |
| laravel/horizon | Queue dashboard/supervisor | MIT | Visibility into orchestrator jobs. |
| laravel/fortify | Auth backend | MIT | 2FA, reset, throttling without UI coupling. |
| laravel/socialite | GitHub OAuth | MIT | §3.8.1. |
| firebase/php-jwt | GitHub App JWT (RS256) | BSD-3 | Tiny, ubiquitous. |
| spatie/laravel-data | Typed DTOs for webhook/agent payloads | MIT | Validation + casting at boundaries (§8.4). |
| spatie/laravel-prometheus (or hand-written exporter) | `/metrics` | MIT | `[TBD: evaluate at M8; hand-roll if it drags dependencies]`. |
| larastan/larastan | Static analysis | MIT | Dev only. |
| laravel/pint | Formatting | MIT | Installed. |
| laravel/dusk (optional) | Browser tests | MIT | Dev only, for realtime UI smoke tests. |
| github.com/docker/docker | Docker client (Go) | Apache-2.0 | Official SDK. |
| github.com/coder/websocket | WS client (Go) | ISC | Minimal. |
| github.com/testcontainers/testcontainers-go | Agent integration tests | MIT | Dev only. |
| Alpine plugins (`@alpinejs/focus`, `@alpinejs/collapse`) | Pines components, modals | MIT | Required by Pines patterns. |
| Chart library: `uPlot` | Time-series charts | MIT | ~40 KB, fast; avoids heavy Chart.js/ApexCharts for §3.9. |
| `@xterm/xterm` (+ fit addon) | Log terminal rendering with ANSI | MIT | Robust ANSI/scrollback handling for §3.5.2. |

## 2. Design Patterns

### 2.1 Architectural Pattern: Modular Monolith with Action classes

**Why:** Many independent concerns (GitHub, scheduling, hosts, jobs, access) share one deployable (§4.1) and must not become a ball of mud.

**Application:** `app/Domain/<Module>` holds Models, Enums, Actions, DTOs, Events, Policies. Modules call each other only through Actions or published Contracts, never reach into another module's models for writes. Livewire/HTTP layers are thin and only call Actions.

```php
// app/Domain/Runners/Actions/ProvisionRunner.php
final class ProvisionRunner
{
    public function __construct(
        private PickHost $pickHost,
        private GithubGateway $github,
        private DispatchHostCommand $dispatch,
    ) {}

    public function __invoke(RunnerPool $pool, ?Job $job = null): ?Runner
    {
        $runner = DB::transaction(fn () => $this->reserveSlot($pool, $job)); // ADR-6
        if (! $runner) return null;                                          // at max_runners

        $jit = $this->github->generateJitConfig($pool, $runner->name, $pool->labels);
        $this->dispatch->start($runner, $jit);                               // outbox, ADR-2
        return $runner;
    }
}
```

### 2.2 State Machine (enum transition tables)

**Why:** Concurrent writers and many failure modes (§3.3.1 edge cases).

```php
enum RunnerState: string
{
    case Provisioning = 'provisioning'; case Idle = 'idle'; case Busy = 'busy';
    case Terminating = 'terminating';   case Gone = 'gone'; case Failed = 'failed';

    /** @return list<self> */
    public function next(): array
    {
        return match ($this) {
            self::Provisioning => [self::Idle, self::Busy, self::Failed, self::Terminating],
            self::Idle         => [self::Busy, self::Terminating, self::Failed],
            self::Busy         => [self::Terminating, self::Failed],
            self::Terminating  => [self::Gone, self::Failed],
            self::Gone, self::Failed => [],
        };
    }
}

// TransitionRunner: UPDATE runners SET state=:to WHERE id=:id AND state IN (:allowed_from)
// Zero rows affected => stale event, silently ignored.
```

### 2.3 Adapter + Retry/Circuit Breaker: GitHub Gateway

**Why:** GitHub is the key external dependency (§4.3) with rate limits and transient failures.

```php
interface GithubGateway
{
    public function generateJitConfig(RunnerPool $pool, string $name, array $labels): JitConfig;
    public function deleteRunner(RunnerPool $pool, int $githubRunnerId): void;
    /** @return iterable<WorkflowJobData> */
    public function listQueuedJobs(Installation $i, Repository $r): iterable;
}

// HttpGithubGateway: installation token cache (55 min, Redis), Http::retry(3, jitter),
// reads X-RateLimit-Remaining/Reset and pauses the 'github:{installation}' queue lane via
// a Redis key checked by a job middleware (RateLimitedByGithub).
```

### 2.4 Strategy: Host drivers

**Why:** Local Docker socket Host and remote-agent Host behave the same to the scheduler (§3.4.1); future Kubernetes/cloud drivers (§12).

```php
interface HostDriver
{
    public function startRunner(Host $h, RunnerSpec $spec): void;
    public function stopRunner(Host $h, Runner $r, bool $force = false): void;
}
final class AgentHostDriver implements HostDriver { /* writes host_commands + broadcast hint */ }
// LocalDockerDriver: same interface; runs the Go agent in-process-equivalent as a sidecar container
// ("local host" is simply an agent enrolled automatically), so v1 has ONE driver; interface exists for v1.2.
```

### 2.5 Transactional Outbox: host commands

**Why:** Guaranteed delivery despite lost WebSocket pushes (ADR-2).

```php
// host_commands: id, host_id, type, payload jsonb, status(pending|sent|acked|failed), attempts, available_at
DB::transaction(function () use ($host, $spec) {
    $cmd = HostCommand::create(['host_id' => $host->id, 'type' => 'runner.start', 'payload' => $spec->toArray()]);
    DB::afterCommit(fn () => broadcast(new HostCommandAvailable($host->id, $cmd->id)));
});
// Agent: on push OR 15s poll -> GET /agent/v1/commands -> execute -> POST /agent/v1/commands/{id}/ack
```

### 2.6 Observer / Domain Events → Broadcasting

**Why:** Real-time UI (§3.5.1: status within 2 s) without coupling orchestrator to UI.

```php
final class JobStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    public function __construct(public Job $job) {}
    public function broadcastOn(): array
    {
        return [new PrivateChannel("repository.{$this->job->repository_id}"), new PrivateChannel('jobs')];
    }
    public function broadcastWith(): array { return JobSummaryData::from($this->job)->toArray(); }
}
```
Channel authorization lives in `routes/channels.php`, delegating to policies (so team grants in §3.8.2 apply to realtime too).

### 2.7 Policy-based RBAC (Least Privilege)

**Why:** §3.8.2 roles scoped by team grants.

```php
final class RunnerPoolPolicy
{
    public function update(User $user, RunnerPool $pool): bool
    {
        return $user->isAdmin() || $user->roleFor($pool)?->atLeast(Role::Operator) === true;
    }
}
// Query side: RunnerPool::query()->visibleTo($user) scope used by every list screen and API endpoint.
```

### 2.8 Chain of Responsibility: webhook pipeline

**Why:** Verify → dedupe → persist → route by event, each step able to stop processing (§3.1.2).

```php
Route::post('/webhooks/github', GithubWebhookController::class)
    ->middleware([VerifyGithubSignature::class, DeduplicateDelivery::class]); // short-circuit with 401 / 200
// Controller stores WebhookDelivery then dispatches ProcessWebhookDelivery; handlers registered per event:
// ['workflow_job' => HandleWorkflowJob::class, 'installation' => HandleInstallation::class, ...]
```

### 2.9 Worker pool + back-pressure (Go agent)

**Why:** Agent must run many container operations and log streams concurrently without unbounded goroutines.

```go
type Executor struct {
    docker  *client.Client
    cmds    chan Command   // from push/poll, de-duplicated by ID
    results chan Result    // batched to server
}

func (e *Executor) Run(ctx context.Context, workers int) {
    for i := 0; i < workers; i++ {
        go func() {
            for cmd := range e.cmds {
                e.results <- e.handle(ctx, cmd) // handlers are idempotent: keyed by runner ID
            }
        }()
    }
}
```

### 2.10 DTO at boundaries

Webhook payloads, agent events, and API requests are parsed into `spatie/laravel-data` objects (validation + casting) before touching domain code (§8.4). Controllers never pass raw arrays into Actions.

## 3. Project Structure

### 3.1 Directory Layout

```
jogger/
├── app/
│   ├── Domain/
│   │   ├── Github/                       # §3.1
│   │   │   ├── Actions/                  # CreateGithubAppFromManifest, SyncInstallation, ReconcileJobs...
│   │   │   ├── Contracts/GithubGateway.php
│   │   │   ├── Data/                     # WorkflowJobData, InstallationData, JitConfig
│   │   │   ├── Gateway/HttpGithubGateway.php, InstallationTokenStore.php
│   │   │   ├── Handlers/                 # HandleWorkflowJob, HandleInstallation, ...
│   │   │   └── Models/                   # GithubApp, Installation, Repository, WebhookDelivery
│   │   ├── Pools/                        # §3.2, §3.3
│   │   │   ├── Actions/                  # CreatePool, UpdatePool, MatchPool, ReconcilePoolCapacity
│   │   │   ├── Data/ScalePolicyData.php
│   │   │   └── Models/RunnerPool.php
│   │   ├── Runners/                      # §3.3.1
│   │   │   ├── Actions/                  # ProvisionRunner, TransitionRunner, TerminateRunner, ReapStuckRunners
│   │   │   ├── Enums/RunnerState.php
│   │   │   └── Models/Runner.php
│   │   ├── Jobs/                         # §3.5 (named Workflow to avoid clashing with Laravel queue Jobs)
│   │   │   ├── Actions/                  # UpsertJobFromWebhook, TransitionJob, AssignJobToRunner
│   │   │   ├── Enums/JobStatus.php, JobConclusion.php
│   │   │   ├── Logs/LogIngestor.php, LogStore.php, SecretMasker.php
│   │   │   └── Models/WorkflowJob.php, JobLog.php
│   │   ├── Hosts/                        # §3.4
│   │   │   ├── Actions/                  # CreateEnrollmentToken, EnrollHost, PickHost, CordonHost, DrainHost, RevokeHost
│   │   │   ├── Contracts/HostDriver.php
│   │   │   ├── Drivers/AgentHostDriver.php
│   │   │   ├── Models/Host.php, HostCommand.php, EnrollmentToken.php
│   │   │   └── Enums/HostStatus.php
│   │   ├── Secrets/                      # §3.6   Models/Secret.php, Actions/ResolveSecretsForPool.php
│   │   ├── Cache/                        # §3.7   (M9) CacheService, models
│   │   ├── Access/                       # §3.8   Models/Team.php, TeamMember, Role enum, Policies/*, AuditLog
│   │   ├── Metrics/                      # §3.9   Actions/RollupMetrics.php, PrometheusExporter.php
│   │   └── Notifications/                # §3.10  AlertRules, channels (mail, webhook, in-app)
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── GithubWebhookController.php
│   │   │   ├── GithubManifestController.php        # manifest callback, OAuth callback
│   │   │   ├── Api/V1/                             # PoolController, JobController, RunnerController, HostController, TokenController
│   │   │   ├── Agent/                              # EnrollController, CommandsController, EventsController, LogsController, BroadcastAuthController
│   │   │   └── MetricsController.php
│   │   ├── Middleware/                             # VerifyGithubSignature, DeduplicateDelivery, AuthenticateAgent, EnsureSetupComplete
│   │   └── Requests/
│   ├── Livewire/
│   │   ├── Overview/Dashboard.php
│   │   ├── Jobs/{Index,Show}.php, Jobs/LogTerminal.php
│   │   ├── Pools/{Index,Form}.php
│   │   ├── Hosts/{Index,EnrollModal}.php
│   │   ├── Runners/Index.php
│   │   ├── Github/{Connect,Installations,Deliveries}.php
│   │   ├── Secrets/Index.php
│   │   ├── Insights/Index.php
│   │   ├── Settings/{General,Tokens,Notifications}.php
│   │   ├── Admin/{Users,Teams,AuditLog}.php
│   │   ├── Setup/FirstRun.php
│   │   └── Shared/CommandPalette.php
│   ├── Events/                                     # JobStatusChanged, RunnerStateChanged, HostStatusChanged, LogChunkReceived
│   ├── Console/Commands/                           # jogger:reconcile, jogger:reap, jogger:prune, jogger:rollup, jogger:install
│   ├── Providers/{AppServiceProvider,FortifyServiceProvider,HorizonServiceProvider}.php
│   └── Support/                                    # Uuid helpers, Clock, Result, PrometheusFormatter
├── bootstrap/
├── config/jogger.php                               # all Jogger-specific config (§8.2)
├── database/{migrations,factories,seeders}/
├── resources/
│   ├── css/app.css                                 # Tailwind 4 @theme tokens (see BRANDING.md later)
│   ├── js/{app.js,echo.js,log-terminal.js,charts.js}
│   └── views/
│       ├── layouts/{app,guest}.blade.php
│       ├── components/ui/                          # Pines-derived: button, input, select, modal, dropdown, tabs, toast, badge, table, card, empty-state, command-palette
│       └── livewire/...                            # one view per Livewire component
├── routes/{web.php,api.php,agent.php,channels.php,console.php}
├── agent/                                          # Go module: github.com/<org>/jogger/agent
│   ├── cmd/jogger-agent/main.go
│   ├── internal/
│   │   ├── config/config.go                        # flags + env + file
│   │   ├── enroll/enroll.go                        # exchange token for credential, store 0600
│   │   ├── transport/{pusher.go,http.go}           # Reverb client, REST client, backoff
│   │   ├── commands/{poller.go,dispatcher.go,types.go}
│   │   ├── docker/{runner.go,network.go,images.go,dind.go,cleanup.go,stats.go}
│   │   ├── logs/shipper.go                         # batching, seq numbers, backpressure
│   │   ├── telemetry/heartbeat.go                  # CPU/mem/disk, Docker version
│   │   └── update/update.go                        # version check, self-update (opt-in)
│   ├── go.mod, go.sum
│   └── Makefile
├── docker/
│   ├── app/Dockerfile                              # control plane image (FrankenPHP)
│   ├── agent/Dockerfile                            # distroless static image for agent
│   ├── runner/Dockerfile                           # default runner image (Ubuntu LTS + actions/runner)
│   ├── runner/entrypoint.sh
│   ├── compose/docker-compose.yml                  # reference install
│   ├── compose/docker-compose.dev.yml
│   └── caddy/Caddyfile                             # optional reverse proxy example
├── tests/{Unit,Feature,Browser}/
├── docs/                                           # SPECIFICATION.md, IMPLEMENTATION.md, TASKS.md, ...
├── .github/workflows/{ci.yml,release.yml,runner-image.yml}
├── composer.json, package.json, vite.config.js, phpunit.xml, pint.json, phpstan.neon
├── Makefile
└── README.md
```

**Structural Philosophy:**
- Domain-oriented grouping inside `app/Domain`; Laravel conventions retained for HTTP/Livewire/Console.
- Boundaries: Livewire/HTTP → Actions → Models/Gateways. Domain modules never import `App\Livewire` or `App\Http`.
- Tests: PHP in `tests/`, Go tests co-located (`*_test.go`).
- Models for jobs are named `WorkflowJob` (table `workflow_jobs`) to avoid collisions with Laravel's queue "Job" concept.

### 3.2 Module Breakdown

#### Module: Github
- **Path**: `app/Domain/Github`
- **Responsibility**: All communication with GitHub and translation of GitHub payloads into domain data.
- **Exports**: `GithubGateway` interface, `WorkflowJobData`, `JitConfig`, webhook handlers.
- **Imports**: Pools (to find matching pools), Jobs (upsert), Runners (via events only).
- **Key Files**: `Gateway/HttpGithubGateway.php`, `Gateway/InstallationTokenStore.php`, `Actions/CreateGithubAppFromManifest.php`, `Actions/ReconcileJobs.php`, `Handlers/HandleWorkflowJob.php`.

#### Module: Pools
- **Path**: `app/Domain/Pools`
- **Responsibility**: Pool definitions, label matching, scale policy evaluation.
- **Exports**: `MatchPool::for(WorkflowJobData): ?RunnerPool`, `ScalePolicy::desiredRunners(RunnerPool): int`.
- **Imports**: Github (installation), Hosts (allowed host labels).
- **Key Files**: `Actions/MatchPool.php` (label superset match, specificity rank), `Actions/ReconcilePoolCapacity.php` (warm pool maintenance).

#### Module: Runners
- **Path**: `app/Domain/Runners`
- **Responsibility**: Runner lifecycle and provisioning (spec §3.3).
- **Exports**: `ProvisionRunner`, `TransitionRunner`, `TerminateRunner`.
- **Imports**: Github (gateway), Hosts (driver, PickHost), Secrets.
- **Key Files**: `Actions/ProvisionRunner.php`, `Actions/ReapStuckRunners.php`, `Enums/RunnerState.php`.

#### Module: Jobs
- **Path**: `app/Domain/Jobs`
- **Responsibility**: Job mirror, statuses, log ingestion and storage.
- **Exports**: `UpsertJobFromWebhook`, `LogIngestor::append(Runner, seq, chunk)`.
- **Key Files**: `Logs/LogIngestor.php` (Redis stream → chunk files, broadcast), `Logs/SecretMasker.php`, `Logs/LogStore.php` (gzip on finalize, truncation marker at cap).

#### Module: Hosts
- **Path**: `app/Domain/Hosts`
- **Responsibility**: Hosts, enrollment, placement, command outbox.
- **Exports**: `HostDriver`, `PickHost`, `EnrollHost`.
- **Key Files**: `Actions/PickHost.php` (score by free CPU/mem, filter cordoned/offline/labels), `Drivers/AgentHostDriver.php`.

#### Module: Access
- **Path**: `app/Domain/Access`
- **Responsibility**: Users, teams, roles, grants, audit.
- **Exports**: `Role` enum, `visibleTo` query scopes, `Audit::record()`.

#### Modules: Secrets, Metrics, Notifications, Cache
- Secrets: encrypted scoped values and precedence resolver. Metrics: minute/hour rollups + Prometheus exporter. Notifications: alert rule evaluation + channels. Cache: M9 only.

### 3.3 Module Dependency Graph

```
Livewire / Http ──▶ Actions (per module)
                         │
 Github ──▶ Pools ──▶ Runners ──▶ Hosts ──▶ (Agent over HTTPS/Reverb)
   │           │          │
   └──▶ Jobs ◀─┴──────────┘         Access (policies) ◀── used by every layer
   Secrets ──▶ Runners             Metrics/Notifications ◀── listen to events only
```
Cycles avoided by: Github → Jobs/Pools via Actions; Runners → Jobs via domain events; Metrics/Notifications are event listeners only.

## 4. Data Layer

### 4.1 Database Schema (PostgreSQL)

Laravel migrations will create these; shown as SQL for clarity. All IDs are UUIDv7 (`HasUuids` on Laravel 13 generates ordered UUIDs).

```sql
CREATE TABLE users (
  id uuid PRIMARY KEY, name varchar(120) NOT NULL, email varchar(255) NOT NULL UNIQUE,
  password varchar(255), github_id bigint UNIQUE, is_admin boolean NOT NULL DEFAULT false,
  two_factor_secret text, two_factor_recovery_codes text, two_factor_confirmed_at timestamptz,
  email_verified_at timestamptz, remember_token varchar(100),
  created_at timestamptz, updated_at timestamptz, deleted_at timestamptz
);

CREATE TABLE teams (id uuid PRIMARY KEY, name varchar(120) NOT NULL, slug varchar(120) NOT NULL UNIQUE, created_at timestamptz, updated_at timestamptz, deleted_at timestamptz);
CREATE TABLE team_user (team_id uuid REFERENCES teams ON DELETE CASCADE, user_id uuid REFERENCES users ON DELETE CASCADE,
  role varchar(16) NOT NULL CHECK (role IN ('admin','operator','viewer')), PRIMARY KEY (team_id, user_id));
CREATE TABLE team_grants (id uuid PRIMARY KEY, team_id uuid NOT NULL REFERENCES teams ON DELETE CASCADE,
  grantable_type varchar(32) NOT NULL, grantable_id uuid NOT NULL, UNIQUE (team_id, grantable_type, grantable_id)); -- installation | pool

CREATE TABLE github_apps (
  id uuid PRIMARY KEY, app_id bigint NOT NULL, slug varchar(100) NOT NULL, client_id varchar(64) NOT NULL,
  client_secret text NOT NULL, private_key text NOT NULL, webhook_secret text NOT NULL,   -- encrypted casts
  api_base_url varchar(255) NOT NULL DEFAULT 'https://api.github.com', created_at timestamptz, updated_at timestamptz
);
CREATE TABLE installations (
  id uuid PRIMARY KEY, github_app_id uuid NOT NULL REFERENCES github_apps, github_installation_id bigint NOT NULL UNIQUE,
  account_login varchar(100) NOT NULL, account_type varchar(8) NOT NULL, status varchar(12) NOT NULL DEFAULT 'active',
  repository_selection varchar(8) NOT NULL, created_at timestamptz, updated_at timestamptz
);
CREATE TABLE repositories (
  id uuid PRIMARY KEY, installation_id uuid NOT NULL REFERENCES installations ON DELETE CASCADE,
  github_repo_id bigint NOT NULL UNIQUE, full_name varchar(200) NOT NULL, private boolean NOT NULL,
  created_at timestamptz, updated_at timestamptz
);
CREATE INDEX ON repositories (installation_id, full_name);

CREATE TABLE runner_pools (
  id uuid PRIMARY KEY, installation_id uuid NOT NULL REFERENCES installations,
  name varchar(100) NOT NULL, scope varchar(12) NOT NULL CHECK (scope IN ('repository','organization')),
  scope_target varchar(200) NOT NULL, runner_group_id bigint,
  labels text[] NOT NULL, image varchar(300) NOT NULL, cpu_limit numeric(5,2), memory_limit_mb int,
  min_idle int NOT NULL DEFAULT 0, max_runners int NOT NULL DEFAULT 5, idle_ttl_seconds int NOT NULL DEFAULT 900,
  docker_mode varchar(8) NOT NULL DEFAULT 'none', persist_tool_cache boolean NOT NULL DEFAULT false,
  allowed_host_labels text[] NOT NULL DEFAULT '{}', status varchar(8) NOT NULL DEFAULT 'active',
  created_at timestamptz, updated_at timestamptz, deleted_at timestamptz,
  CHECK (max_runners >= min_idle), UNIQUE (installation_id, name)
);
CREATE INDEX runner_pools_labels_gin ON runner_pools USING gin (labels);

CREATE TABLE hosts (
  id uuid PRIMARY KEY, name varchar(100) NOT NULL UNIQUE, labels text[] NOT NULL DEFAULT '{}',
  status varchar(12) NOT NULL DEFAULT 'pending', arch varchar(10), os varchar(20), docker_version varchar(20),
  cpu_total numeric(6,2), memory_total_mb int, cpu_used numeric(6,2), memory_used_mb int, disk_free_gb int,
  agent_version varchar(20), credential_hash varchar(128), last_seen_at timestamptz,
  created_at timestamptz, updated_at timestamptz, deleted_at timestamptz
);
CREATE TABLE enrollment_tokens (id uuid PRIMARY KEY, token_hash varchar(128) NOT NULL UNIQUE, host_name varchar(100),
  expires_at timestamptz NOT NULL, used_at timestamptz, created_by uuid REFERENCES users);
CREATE TABLE host_commands (
  id uuid PRIMARY KEY, host_id uuid NOT NULL REFERENCES hosts ON DELETE CASCADE, type varchar(40) NOT NULL,
  payload jsonb NOT NULL, status varchar(8) NOT NULL DEFAULT 'pending', attempts int NOT NULL DEFAULT 0,
  available_at timestamptz NOT NULL DEFAULT now(), acked_at timestamptz, result jsonb, created_at timestamptz
);
CREATE INDEX host_commands_pending ON host_commands (host_id, available_at) WHERE status IN ('pending','sent');

CREATE TABLE runners (
  id uuid PRIMARY KEY, pool_id uuid NOT NULL REFERENCES runner_pools, host_id uuid REFERENCES hosts,
  github_runner_id bigint, name varchar(100) NOT NULL UNIQUE, container_id varchar(80),
  state varchar(12) NOT NULL DEFAULT 'provisioning', exit_reason varchar(40), exit_code int,
  state_changed_at timestamptz NOT NULL DEFAULT now(), started_at timestamptz, finished_at timestamptz,
  created_at timestamptz, updated_at timestamptz
);
CREATE INDEX runners_pool_live ON runners (pool_id) WHERE state IN ('provisioning','idle','busy');
CREATE INDEX runners_host_live ON runners (host_id) WHERE state IN ('provisioning','idle','busy');

CREATE TABLE workflow_jobs (
  id uuid PRIMARY KEY, provider varchar(12) NOT NULL DEFAULT 'github', github_job_id bigint NOT NULL UNIQUE,
  github_run_id bigint NOT NULL, repository_id uuid NOT NULL REFERENCES repositories,
  workflow_name varchar(200), job_name varchar(200) NOT NULL, labels text[] NOT NULL,
  status varchar(12) NOT NULL, conclusion varchar(16), head_sha varchar(40), head_branch varchar(250), actor varchar(100),
  html_url varchar(500), pool_id uuid REFERENCES runner_pools, runner_id uuid REFERENCES runners,
  unmatched_reason varchar(200), queued_at timestamptz, started_at timestamptz, completed_at timestamptz,
  created_at timestamptz, updated_at timestamptz
);
CREATE INDEX ON workflow_jobs (status, queued_at);
CREATE INDEX ON workflow_jobs (repository_id, created_at DESC);
CREATE INDEX ON workflow_jobs (pool_id, created_at DESC);

CREATE TABLE job_logs (job_id uuid PRIMARY KEY REFERENCES workflow_jobs ON DELETE CASCADE, storage_path varchar(300),
  size_bytes bigint NOT NULL DEFAULT 0, line_count int NOT NULL DEFAULT 0, truncated boolean NOT NULL DEFAULT false,
  last_seq bigint NOT NULL DEFAULT 0, finalized_at timestamptz, expires_at timestamptz);

CREATE TABLE webhook_deliveries (id uuid PRIMARY KEY, delivery_id uuid NOT NULL UNIQUE, event varchar(40) NOT NULL,
  action varchar(40), payload jsonb NOT NULL, status varchar(12) NOT NULL DEFAULT 'received', error text,
  received_at timestamptz NOT NULL DEFAULT now(), processed_at timestamptz);
CREATE INDEX ON webhook_deliveries (received_at);

CREATE TABLE secrets (id uuid PRIMARY KEY, scope_type varchar(12) NOT NULL, scope_id uuid, name varchar(100) NOT NULL,
  value text NOT NULL, created_at timestamptz, updated_at timestamptz, UNIQUE (scope_type, scope_id, name));

CREATE TABLE audit_logs (id uuid PRIMARY KEY, user_id uuid REFERENCES users ON DELETE SET NULL, action varchar(60) NOT NULL,
  target_type varchar(40), target_id uuid, metadata jsonb, ip inet, created_at timestamptz NOT NULL DEFAULT now());
CREATE INDEX ON audit_logs (created_at DESC);

CREATE TABLE metric_samples (bucket timestamptz NOT NULL, resolution varchar(6) NOT NULL, subject_type varchar(8) NOT NULL,
  subject_id uuid, queue_depth int, runners_busy int, runners_idle int, queue_wait_p50_ms int, queue_wait_p95_ms int,
  jobs_started int, jobs_succeeded int, jobs_failed int, busy_seconds bigint,
  PRIMARY KEY (resolution, subject_type, subject_id, bucket));
-- Also: personal_access_tokens (Laravel Sanctum-style, hashed, role-scoped), cache entries (M9),
-- notification_rules / notifications (§3.10), sessions, jobs, failed_jobs (Laravel).
```

Log **bodies** are not in Postgres: stored as chunk files on the `logs` filesystem disk (local volume by default, S3-compatible optional), see 4.3.

### 4.2 Migration Strategy

Laravel migrations in `database/migrations`, one table-group per file, timestamp-prefixed, always with a working `down()`. Destructive migrations are avoided post-1.0 (expand/contract). `jogger:install` runs `migrate --force` at container start in the web image entrypoint (single-run guarded by an advisory lock so multi-replica starts are safe).

### 4.3 Data Access Pattern

Eloquent Active Record (spec's entities map ~1:1 to tables), with query scopes (`visibleTo`, `live`, `matching`) and all writes wrapped in Actions. No separate Repository layer (Eloquent already provides it; avoiding needless indirection per the pattern guide).

```php
// Label match: pool labels must be a superset of job labels (Postgres array containment)
public function scopeMatching(Builder $q, array $jobLabels): Builder
{
    return $q->where('status', 'active')->whereRaw('labels @> ?::text[]', [$this->toPgArray($jobLabels)]);
}
```

**Logs path:** agent → `POST /agent/v1/runners/{id}/logs` (batched, `seq` per runner) → `LogIngestor`:
1. De-dupes by `seq` (idempotent retries), masks secrets, appends to a Redis Stream (`logs:{job}`) for live tailing.
2. Broadcasts `LogChunkReceived` on `private-job.{id}` (payload: seq + text).
3. A flusher job appends stream data to `storage/logs/{yyyy}/{mm}/{job}.log` and finalizes (gzip, size cap with truncation marker) when the job completes.

### 4.4 Caching Strategy

- Installation access tokens: Redis, TTL 55 min.
- Dashboard aggregates: Redis, 5 s TTL, invalidated by events.
- Metrics read from `metric_samples` rollups (minute rolled up every minute, hour every hour).
- No query caching of authorization (must be accurate).

## 5. API Implementation

### 5.1 Route Structure

**web.php** (session auth + Livewire pages)

| Path | Component | Middleware |
|------|-----------|-----------|
| `/setup` | Setup\FirstRun | `guest`, only when no users |
| `/login`, `/two-factor-challenge`, `/forgot-password` | Fortify | `guest` |
| `/auth/github/redirect`, `/auth/github/callback` | SocialiteController | `guest` |
| `/` | Overview\Dashboard | `auth` |
| `/jobs`, `/jobs/{job}` | Jobs\Index, Jobs\Show | `auth`, policy |
| `/pools`, `/pools/create`, `/pools/{pool}` | Pools\Index, Pools\Form | `auth`, policy |
| `/hosts` | Hosts\Index | `auth` |
| `/runners` | Runners\Index | `auth` |
| `/github`, `/github/deliveries` | Github\* | `auth`, `can:admin` |
| `/github/manifest/callback` | GithubManifestController | `auth`, `can:admin` |
| `/secrets`, `/insights`, `/settings/*`, `/admin/*` | respective | `auth` + role |

**routes/api.php** (`/api/v1`, token auth, `throttle:api` 120/min)

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| GET | /pools | PoolController@index | filter by status |
| POST | /pools | PoolController@store | operator+ |
| PATCH | /pools/{pool} | PoolController@update | pause/resume via `status` |
| GET | /jobs | JobController@index | filters, cursor pagination |
| GET | /jobs/{job}/logs | JobController@logs | streams gz/plain |
| GET | /runners | RunnerController@index | |
| GET | /hosts | HostController@index | |
| POST | /hosts/enrollment-tokens | HostController@enrollmentToken | admin |

**routes/agent.php** (`/agent/v1`, agent credential auth)

| Method | Path | Purpose |
|--------|------|---------|
| POST | /enroll | Exchange enrollment token → credential (no auth; token-based) |
| POST | /broadcasting/auth | Authorize Reverb `private-host.{id}` subscription |
| POST | /heartbeat | Capacity + version, every 10 s |
| GET | /commands | Pending commands (also used as 15 s poll) |
| POST | /commands/{id}/ack | Result of command |
| POST | /events | Batched runner events (`container_started`, `container_exited`, ...) |
| POST | /runners/{id}/logs | Batched log chunks with `seq` |
| GET | /version | Latest agent version (for update notice) |

**Other:** `POST /webhooks/github`, `GET /metrics` (bearer), `GET /up` (health).

### 5.2 Request/Response Contract

Success (REST API):
```json
{ "data": { "id": "0197…", "name": "linux-large", "labels": ["self-hosted","linux","x64"] }, "meta": { "request_id": "req_abc123" } }
```
Error (matches spec §6.5, extended):
```json
{ "error": { "code": "POOL_LIMIT_EXCEEDED", "message": "Pool has reached max_runners (10).", "details": { "pool_id": "0197…" }, "request_id": "req_abc123" } }
```
Agent command envelope:
```json
{ "id": "uuid", "type": "runner.start", "protocol": 1,
  "payload": { "runner_id": "uuid", "name": "jogger-9f2c", "image": "ghcr.io/…/runner:2.x", "jit_config": "…",
               "cpu": 2.0, "memory_mb": 4096, "env": {"K":"V"}, "docker_mode": "none", "labels": {"jogger.runner":"uuid"} } }
```
Other command types: `runner.stop`, `image.pull`, `host.prune`, `agent.update_notice`. Agents ignore unknown types by acking `unsupported`.

### 5.3 Validation Approach

Form Requests for API controllers; `spatie/laravel-data` for webhook and agent payloads; Livewire forms use `Form` objects with attribute rules. Label strings restricted to `^[A-Za-z0-9._-]{1,64}$`; image refs validated by a regex for OCI references.

### 5.4 Authentication Flows

**Browser:** Fortify session login → optional TOTP → session regeneration. GitHub OAuth through Socialite using the GitHub App's client credentials; link-by-verified-email only if the existing local account's email is verified, otherwise require explicit link from account settings.

**API tokens:** hashed (sha256) personal tokens with `role` ability; shown once.

**Agent:**
```
1. Admin creates enrollment token (hash stored, 1 h TTL, single use).
2. Agent: POST /agent/v1/enroll {token, hostname, facts} → {host_id, credential, reverb: {host, port, key}}
3. Agent stores credential (0600). All later calls: Authorization: Bearer <credential>; server stores only sha256.
4. Agent opens Reverb WS, subscribes to private-host.{host_id} via /broadcasting/auth.
5. Revocation: host.status=revoked ⇒ credential rejected on next request, WS auth refused.
```

**Webhook:** `X-Hub-Signature-256` HMAC compared with `hash_equals` against the app's webhook secret (looked up by the `X-GitHub-Hook-Installation-Target-ID` app id).

## 6. Frontend Implementation

### 6.1 Component Architecture

- **Layout:** `layouts/app.blade.php` — sidebar nav (collapsible), top bar with ⌘K palette, theme toggle, user menu, toast region.
- **Pages:** full-page Livewire components (`#[Layout('layouts.app')]`), one per screen in §7.2.
- **UI primitives:** `resources/views/components/ui/*` derived from Pines (Alpine + Tailwind): `<x-ui.button>`, `<x-ui.modal>`, `<x-ui.dropdown>`, `<x-ui.tabs>`, `<x-ui.toast>`, `<x-ui.badge status="running">`, `<x-ui.stat>`, `<x-ui.table>`. Domain widgets (e.g. `<x-job-status-pill>`, `<x-pool-meter>`) compose the primitives.
- Naming: Livewire components `App\Livewire\<Area>\<Screen>`; Blade components kebab-case.

**UI reference (user-provided): vertical step-progress card.** Light rounded card, title left with an "N of M" counter and a slim progress bar on the right; below, a vertical stepper where each step has a status node joined by a connector line, a primary label, and an optional muted secondary line (a metric such as a duration or count).
- Node states: **done** = filled green circle with check, connector to next step is green; **active** = ring with a spinning arc; **pending** = empty grey ring, grey connector. Active label is slightly emphasized, pending labels muted.
- Component: `<x-ui.stepper :steps="..." title="..." />` with step DTO `{label, detail?, state: done|active|pending|failed}`; the `failed` state (red node with an X) and `skipped` (dashed) are additions for our domain.
- Used for: **Job lifecycle** on Job detail (Queued 3 s → Runner provisioned 8 s → Running → Completed), **Runner lifecycle** in the runner drawer (Provisioning → Idle → Busy → Terminating), **Host enrollment** wizard (Create token → Run command → Agent connected → Capacity detected), **GitHub connect** onboarding (Create app → Install → Add pool → Run a job), and the Overview "getting started" checklist.
- Realtime: step state and the metric lines update live through the same Echo events (`JobStatusChanged`, `RunnerStateChanged`, `HostStatusChanged`); the spinner and bar respect `prefers-reduced-motion`.
- This visual language (soft cards, hairline borders, generous radius, muted secondary text, one saturated green accent for success, dark-ink progress bar) sets the baseline tokens for BRANDING.md.

### 6.2 State Management & Realtime

- Server state lives in Livewire components; no client store.
- Realtime: components declare `#[On('echo-private:repository.{id},JobStatusChanged')]` handlers that refresh only the affected row/summary (`$refresh` for small lists, targeted property update for large tables). Echo is initialized in `resources/js/echo.js` (Reverb config from `VITE_REVERB_*`).
- **Log terminal:** `log-terminal.js` Alpine component wrapping xterm.js: loads scrollback via a signed endpoint (`GET /jobs/{job}/log?from=seq`) then subscribes to `private-job.{id}` and writes chunks; reconnects fetch from `last_seq` to avoid gaps; virtualization is handled by xterm (scrollback limit 50k lines, "load earlier" button for the rest).
- Optimistic UI only for pause/resume toggles (revert on error).

### 6.3 Routing & Guards

Routes in §5.1. `EnsureSetupComplete` middleware redirects to `/setup` until the first admin exists and to `/github` banner flow until a GitHub App is connected. Policies gate every component `mount()` and action method.

### 6.4 Styling

Tailwind 4 with design tokens in `@theme` (colors, radius, shadows, font) — finalized in BRANDING.md. Dark mode via `class` strategy with system default and a persisted toggle. Density tuned for data tables (compact rows, tabular numerals). Motion respects `prefers-reduced-motion`. Mobile: sidebar becomes a drawer, tables render as stacked cards below `md`.

## 7. Error Handling Strategy

### 7.1 Error Classification

| Category | Example | HTTP | Logged | User sees |
|----------|---------|------|--------|-----------|
| Validation | Bad label chars | 422 | debug | Field errors |
| Auth | Invalid token / signature | 401 | info (warn for repeated bad signatures) | "Unauthorized" |
| Forbidden | Viewer edits pool | 403 | warn | "Not authorized" |
| Not found | Unknown job | 404 | debug | "Not found" |
| Business | Pool at max_runners | 409/422 | info | Specific reason |
| Upstream (GitHub) | Rate limited / 5xx | n/a (async) | warn | UI banner + retry |
| Host/agent | Docker pull failed | n/a (async) | error | Runner `failed` with reason |
| Internal | Unexpected exception | 500 | error + report | "Something went wrong" + request ID |

### 7.2 Error Propagation

- Domain exceptions (`PoolAtCapacity`, `GithubRateLimited`, `HostUnavailable`) extend `JoggerException` with a machine code; HTTP/Livewire exception handlers map them. Queue jobs map them to `release($delay)` (retryable) or `fail()`.
- Async failures are **recorded as state** (runner `failed` + `exit_reason`, job `unmatched_reason`) so the UI explains why, rather than only logging.
- Agent: all command handlers return a `Result{ok, code, message}`; transport errors retry with exponential backoff + jitter (1 s → 60 s cap).

## 8. Configuration

### 8.1 Config Sources

Laravel `.env` → `config/jogger.php`. Operational settings (retention, alerts) are stored in a `settings` table, editable in UI, falling back to config defaults. Agent: flags > env > `/etc/jogger-agent/config.toml` > defaults.

### 8.2 Config Schema (selected)

| Key | Type | Default | Env Var | Description |
|-----|------|---------|---------|-------------|
| app.url | string | — | `APP_URL` | Public URL (used for webhook/OAuth URLs) |
| db | — | pgsql | `DB_*` | PostgreSQL connection |
| redis | — | — | `REDIS_*` | Queue, cache, locks, Reverb scaling |
| jogger.log_retention_days | int | 30 | `JOGGER_LOG_RETENTION_DAYS` | §3.5.2 |
| jogger.job_retention_days | int | 90 | `JOGGER_JOB_RETENTION_DAYS` | §5.3 |
| jogger.log_max_bytes_per_job | int | 104857600 | `JOGGER_LOG_MAX_BYTES` | §3.5.2 cap |
| jogger.runner_provision_timeout | int s | 180 | `JOGGER_PROVISION_TIMEOUT` | §3.3.1 |
| jogger.host_offline_after | int s | 30 | `JOGGER_HOST_OFFLINE_AFTER` | §3.4.1 |
| jogger.reconcile_interval | int s | 60 | `JOGGER_RECONCILE_INTERVAL` | §3.1.3 |
| jogger.metrics_token | string | — | `JOGGER_METRICS_TOKEN` | `/metrics` bearer |
| jogger.local_host_enabled | bool | false | `JOGGER_LOCAL_HOST` | §3.4.1 local socket Host |
| jogger.default_runner_image | string | `ghcr.io/<org>/jogger-runner:latest` | `JOGGER_RUNNER_IMAGE` | §3.2.2 |
| reverb.* | — | — | `REVERB_*` | Host/port/keys, also handed to agents on enroll |
| filesystems.logs | disk | local | `JOGGER_LOG_DISK` | local or S3-compatible |

## 9. Testing Strategy

### 9.1 Test Pyramid

| Level | Tool | Scope | Target |
|-------|------|-------|--------|
| Unit (PHP) | PHPUnit | State machines, MatchPool, PickHost, SecretMasker, scale policy | 90% on `app/Domain/**/Actions` |
| Feature (PHP) | PHPUnit + Livewire testing + PostgreSQL (CI service) | Webhook pipeline, provisioning flow with `Http::fake()` and a fake `HostDriver`, policies, API, agent endpoints | All endpoints, happy + error paths |
| Concurrency | PHPUnit with parallel processes | N concurrent `queued` webhooks never exceed `max_runners` (ADR-6) | Critical invariant tests |
| Unit (Go) | `go test` | Command dispatch, backoff, log batching, Pusher framing | 80% |
| Integration (Go) | testcontainers | Real Docker: start/stop container with limits, cleanup, DinD | Core paths |
| E2E | Dusk (one smoke suite) + script against real GitHub test repo (manual/nightly) | Login → connect → job → live log | 2–3 critical flows |

### 9.2 Test Patterns

- Factories for every model (`RunnerPoolFactory::busy()`, etc.), including webhook payload fixtures captured from real GitHub deliveries (stored in `tests/Fixtures/github/`).
- `FakeGithubGateway` and `FakeHostDriver` bound in tests; GitHub-facing HTTP tests use `Http::fake()` with recorded responses.
- AAA structure; no sleeping — use `Carbon::setTestNow` and sync queues.

### 9.3 CI Pipeline

```
Push/PR → Pint + Larastan + golangci-lint → PHPUnit (pgsql+redis services) → go test (incl. testcontainers)
        → npm build → build images (buildx, amd64+arm64) → [on tag: push images, release agent binaries]
```

## 10. Security Implementation

### 10.1 Input Sanitization Points

Webhook (signature → DTO), agent endpoints (credential → DTO), Livewire forms (rules), API Form Requests. Image refs and labels are strictly validated because they end up as container arguments; the agent applies a second, independent validation and never builds shell strings (uses the Docker SDK).

### 10.2 Secret Management

App key encrypts secrets/GitHub App key (`encrypted` casts). Pool/installation secrets are injected as container env at start only, masked in logs (exact-value masking in `SecretMasker`, including base64 variants). `APP_KEY` rotation supported via `jogger:rotate-key` (re-encrypts rows; M10).

### 10.3 Runner Container Hardening (agent-enforced)

```go
HostConfig{
  SecurityOpt: []string{"no-new-privileges"},
  CapDrop: []string{"ALL"},  // minimal CapAdd only when docker_mode needs it
  Resources:  container.Resources{NanoCPUs: …, Memory: …, PidsLimit: ptr(int64(2048))},
  NetworkMode: "<per-runner private network>",
  AutoRemove: true, ReadonlyRootfs: false, // runner needs a writable workspace; /work is tmpfs/volume removed after job
}
```
Labels `jogger.runner=<id>` and `jogger.host=<id>` identify containers for orphan cleanup.

### 10.4 Headers and Web Security

CSP (self + Reverb host; nonce for inline Alpine via the CSP build of Alpine), HSTS when behind HTTPS, `X-Frame-Options: DENY`, same-site cookies, CSRF on all web routes (webhook and agent routes excluded, authenticated by signature/credential).

## 11. Deployment

### 11.1 Build Commands

```bash
composer install --no-dev --optimize-autoloader && npm ci && npm run build   # control plane assets
cd agent && CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -trimpath -ldflags "-s -w -X main.version=$VERSION" -o dist/jogger-agent-linux-amd64 ./cmd/jogger-agent
```

### 11.2 Images and Compose

- `docker/app/Dockerfile`: multi-stage (composer + node build stage → FrankenPHP runtime). One image, role selected by command: `web` (default), `horizon`, `scheduler` (`schedule:work`), `reverb` (`reverb:start`).
- `docker/agent/Dockerfile`: `FROM gcr.io/distroless/static` + binary; needs `/var/run/docker.sock` mounted.
- Reference `docker-compose.yml` services: `app`, `horizon`, `scheduler`, `reverb`, `postgres`, `redis`, optional `caddy`, optional `agent` (local host).

```yaml
services:
  app:      { image: ghcr.io/<org>/jogger:latest, env_file: .env, depends_on: [postgres, redis], volumes: [logs:/app/storage/logs-data] }
  horizon:  { image: ghcr.io/<org>/jogger:latest, command: php artisan horizon, env_file: .env }
  scheduler: { image: ghcr.io/<org>/jogger:latest, command: php artisan schedule:work, env_file: .env }
  reverb:   { image: ghcr.io/<org>/jogger:latest, command: php artisan reverb:start --host=0.0.0.0, env_file: .env }
  postgres: { image: postgres:17, volumes: [pg:/var/lib/postgresql/data] }
  redis:    { image: redis:7-alpine }
```

### 11.3 Health Check

`GET /up` (Laravel health) plus `GET /health` (admin-only detail): DB, Redis, Reverb reachability, queue lag, GitHub App auth, hosts online count. Compose healthchecks use `/up`.

### 11.4 Monitoring

Structured JSON logs (`LOG_CHANNEL=stderr` + JSON formatter) with `request_id`; Horizon for queues; `/metrics` Prometheus endpoint (queue depth, runners by state, jobs by status, queue wait histogram, host capacity, GitHub rate-limit remaining). Agent logs JSON to stdout.

## 12. Development Workflow

### 12.1 Local Setup

```bash
# existing Laravel 13 skeleton in repo root
composer install && cp .env.example .env && php artisan key:generate
# switch .env to pgsql + redis (docker compose -f docker/compose/docker-compose.dev.yml up -d postgres redis)
php artisan migrate && npm install
composer run dev            # web + queue/horizon + vite (extend the dev script to include reverb)
php artisan reverb:start    # separate terminal until added to the dev script
cd agent && go run ./cmd/jogger-agent --server http://localhost:8000 --token <enrollment-token>
```
For GitHub webhooks in development use a tunnel (smee.io/cloudflared) and the manifest flow's configurable URL.

### 12.2 Code Standards

Pint (Laravel preset), Larastan level 6, `gofmt` + `golangci-lint`, strict types in new PHP files. Conventional Commits. Pre-commit hook (optional) runs Pint + `go vet`.

### 12.3 Git Workflow

Trunk-based: short-lived feature branches (`feat/…`, `fix/…`), PRs required, squash merge, SemVer tags `vX.Y.Z` trigger release workflow (images + agent binaries + changelog).

## 13. Spec Coverage Map

| Spec § | Implemented by |
|--------|----------------|
| 3.1 GitHub integration | Domain/Github, `GithubWebhookController`, `ReconcileJobs` |
| 3.2 Pools / images | Domain/Pools, `docker/runner`, Livewire Pools |
| 3.3 Scheduling | Domain/Runners + Hosts (`ProvisionRunner`, `PickHost`), ADR-3/5/6 |
| 3.4 Hosts and agents | Domain/Hosts + `agent/` (ADR-2) |
| 3.5 Jobs and logs | Domain/Jobs, `log-terminal.js`, ingestion pipeline (§4.3) |
| 3.6 Secrets | Domain/Secrets |
| 3.7 Caching | Tool-cache volumes (v1), M9 cache service (ADR-7) |
| 3.8 Access | Domain/Access, Fortify, Socialite |
| 3.9 Metrics | Domain/Metrics, Insights screen, `/metrics` |
| 3.10 Notifications | Domain/Notifications |
| 8 Security | §10, ADR-3/8, agent hardening |
| 9 Deployment | §11 |

## 14. Risks and Verify-at-Build Items

- **JIT API details:** confirm endpoint paths, required permissions (repo "Administration", org "Self-hosted runners"), runner group handling, and idle JIT runner expiry against current GitHub docs while building M3.
- **Horizon version vs Laravel 13:** confirm during install; fall back to plain `queue:work` supervisors if blocked.
- **Reverb as an agent channel:** verify private-channel auth from a non-browser Pusher client works as designed; fallback is poll-only (15 s) with a shorter interval, which still meets correctness but not the 15 s start-latency target.
- **Actions runner image updates:** runner binary must stay within GitHub's version window; the runner-image workflow should rebuild weekly and on upstream releases.
- **actions/cache protocol (M9):** non-trivial and may change; keep experimental.
- **Public-repo safety:** enforce the warning in §8.5 of the spec at pool creation, not just docs.
