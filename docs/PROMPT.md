# Jogger — Implementation Prompt

You are building **Jogger** from the existing Laravel 13 skeleton in the current directory (`/var/www/personnal/jogger`). This prompt is self-contained: do not ask for other documents. Follow the build order exactly, run the checkpoints, and make every decision stated here rather than inventing alternatives. If something is marked `VERIFY`, check current upstream documentation before coding it and note the outcome in `docs/DECISIONS.md`.

## Working rules

1. Work in order (Step 1 → Step 62). Do not start a step before its dependencies pass their checkpoint.
2. Write the test first where practical; every step ends with its tests green.
3. Before finishing each step run: `vendor/bin/pint`, `vendor/bin/phpstan analyse` (level 6), `vendor/bin/phpunit` (PHP) and `gofmt -l . && go vet ./... && go test ./...` inside `agent/` (Go steps).
4. New PHP files start with `declare(strict_types=1);`. Follow Laravel conventions. Use Eloquent (Active Record) + Action classes; no extra repository layer.
5. Commit after each step with a Conventional Commit message. Never commit `.env` or secrets.
6. If a requirement conflicts with reality (package incompatible with Laravel 13, API differs), choose the smallest deviation, document it in `docs/DECISIONS.md`, and continue.
7. The repo's `CLAUDE.md` asks for Laravel Boost; install it (`composer require laravel/boost --dev && php artisan boost:install`) if the MCP server is unavailable, and read the generated `AGENTS.md` afterwards.

## Project overview

Jogger is an open-source, self-hosted control plane that runs **GitHub Actions jobs on your own Docker hosts**. It connects to GitHub through a **GitHub App**, receives `workflow_job` webhooks, and provisions **ephemeral one-job runners** (the official `actions/runner` binary in a Docker container, started with a GitHub **JIT runner config**) on one or more Docker hosts. A small **Go agent** on each host executes commands from the control plane and streams logs/events back. A **Livewire** web UI shows the queue, runners, hosts, pools, and live job logs in real time through **Laravel Reverb**. Roles: admin, operator, viewer via teams. Installed with `docker compose up`. v1 manages runners for jobs that live on github.com; a native workflow engine is a later phase (keep `provider = 'github'` on jobs so it can be added).

Non-goals for v1: Windows/macOS runners, Kubernetes backend, GitHub Enterprise Server support, executing workflow YAML natively, hosting code, VM isolation, multi-tenant billing.

## Tech stack

| Layer | Technology | Version |
|-------|-----------|---------|
| Control plane | PHP / Laravel | PHP ≥ 8.3 (dev box 8.5) / Laravel 13.x (installed) |
| UI | Livewire + Alpine.js + Tailwind CSS | Livewire ^4.4, Alpine (bundled), Tailwind ^4 (installed) |
| Component kit | Pines UI (devdojo.com/pines) patterns, copied into `resources/views/components/ui` | n/a |
| Realtime | laravel/reverb | ^1.12 |
| Queues | laravel/horizon | ^5.50 (`VERIFY` Laravel 13 support; fallback `queue:work` supervisors) |
| Auth | laravel/fortify ^1.41, laravel/socialite ^5.31 | |
| DTOs | spatie/laravel-data | latest compatible |
| JWT | firebase/php-jwt | latest compatible |
| DB / cache | PostgreSQL 17, Redis 7 | |
| Static analysis / style | larastan/larastan (level 6), laravel/pint | |
| Tests | PHPUnit ^12.5, Laravel Dusk (browser smoke) | |
| JS libs | laravel-echo, pusher-js, @alpinejs/focus, @alpinejs/collapse, @xterm/xterm, @xterm/addon-fit, uplot | latest |
| Agent | Go ≥ 1.25 (dev box 1.26); `github.com/docker/docker/client`, `github.com/coder/websocket`, testcontainers-go (dev) | |
| Runtime image | FrankenPHP (`dunglas/frankenphp`) | latest stable |
| CI/CD | GitHub Actions; images to GHCR (amd64+arm64) | |

## Architecture

- **Modular monolith** in `app/Domain/<Module>` (Github, Pools, Runners, Jobs, Hosts, Secrets, Cache, Access, Metrics, Notifications). UI/HTTP call **Actions**; modules do not write to each other's models directly. Cross-module reactions use domain events.
- **Agent transport (decided):** server→agent *hint* via Reverb private channel `private-host.{id}` (Pusher protocol); everything else agent→server over HTTPS REST. Commands are persisted in `host_commands` (transactional outbox) with ack/retry; the agent also **polls `GET /agent/v1/commands` every 15 s and on reconnect**. A push is only a hint to fetch.
- **Ephemeral runners:** per job/warm runner call GitHub `generate-jitconfig` (repo or org endpoint; `VERIFY` current paths, required permissions and idle expiry), pass `JIT_CONFIG` env to the container, entrypoint runs `./run.sh --jitconfig "$JIT_CONFIG"`. Destroy the container and delete the GitHub runner after the job.
- **Concurrency:** provisioning runs in a DB transaction that locks the pool row (`SELECT … FOR UPDATE`), counts live runners (`provisioning|idle|busy`), inserts the runner row, commits, then calls GitHub and dispatches the host command. Redis locks only dedupe work.
- **State machines:** `RunnerState` and `JobStatus` enums with explicit transition tables; writes via `UPDATE … WHERE state IN (allowed_from)`; zero rows affected = stale event, ignore silently.
- **Caching in v1:** optional per-pool persistent tool-cache volume (`/opt/hostedtoolcache`) and per-host build-cache pruning. An `actions/cache`-compatible service is experimental (Steps 54–55) and behind `JOGGER_CACHE_ENABLED`.
- **Docker-in-job:** opt-in `docker_mode=dind` → per-runner private network + rootless DinD sidecar with TLS; never mount the host Docker socket into runners.

## Directory structure

```
jogger/
├── app/
│   ├── Domain/
│   │   ├── Github/{Actions,Contracts,Data,Gateway,Handlers,Models,Support,Testing}
│   │   ├── Pools/{Actions,Data,Models}
│   │   ├── Runners/{Actions,Enums,Models}
│   │   ├── Jobs/{Actions,Enums,Logs,Models}          # model: WorkflowJob (table workflow_jobs)
│   │   ├── Hosts/{Actions,Contracts,Drivers,Models,Enums}
│   │   ├── Secrets/{Actions,Contracts,Models}
│   │   ├── Cache/   Access/{Models,Enums,Policies,Concerns}   Metrics/   Notifications/{Alerts,Channels}
│   ├── Http/{Controllers/{Api/V1,Agent,Auth},Middleware,Requests}
│   ├── Livewire/{Overview,Jobs,Pools,Hosts,Runners,Github,Secrets,Insights,Settings,Admin,Setup,Shared}
│   ├── Events/  Console/Commands/  Providers/  Support/
├── config/jogger.php
├── database/{migrations,factories,seeders}
├── resources/{css/app.css, js/{app.js,echo.js,log-terminal.js,charts.js}, views/{layouts,components/ui,livewire,dev}}
├── routes/{web.php,api.php,agent.php,channels.php,console.php}
├── agent/                       # Go module
│   ├── cmd/jogger-agent/main.go
│   └── internal/{config,enroll,transport,commands,docker,logs,telemetry,update,events}
├── docker/{app,agent,runner,compose,caddy}
├── tests/{Unit,Feature,Browser,Fixtures/github,Script}
├── docs/  .github/workflows/{ci.yml,release.yml,runner-image.yml}
├── composer.json package.json vite.config.js phpunit.xml pint.json phpstan.neon Makefile README.md
```

## Install commands

```bash
composer require livewire/livewire laravel/horizon laravel/fortify laravel/socialite laravel/reverb firebase/php-jwt spatie/laravel-data
composer require --dev larastan/larastan laravel/dusk
npm install laravel-echo pusher-js @alpinejs/focus @alpinejs/collapse @xterm/xterm @xterm/addon-fit uplot
cd agent && go mod init github.com/<org>/jogger/agent \
  && go get github.com/docker/docker@latest github.com/coder/websocket@latest \
  && go get -t github.com/testcontainers/testcontainers-go@latest
```
`.env` essentials: `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `BROADCAST_CONNECTION=reverb`, `REVERB_*`, `APP_URL`, `JOGGER_*` (below). Dev services: `docker/compose/docker-compose.dev.yml` with `postgres:17`, `redis:7-alpine`.

## Design tokens (put in `resources/css/app.css`)

```css
@import 'tailwindcss';
@custom-variant dark (&:where(.dark, .dark *));
@theme {
  --font-sans: 'Instrument Sans', ui-sans-serif, system-ui, sans-serif;
  --font-mono: 'JetBrains Mono', ui-monospace, Menlo, monospace;
  --color-ink:#14171c; --color-bg:#f6f7f9; --color-surface:#fff; --color-border:#e3e6eb; --color-border-strong:#c9ced6;
  --color-text:#14171c; --color-text-secondary:#5b6270;
  --color-success:#0f7a57; --color-error:#b42318; --color-warning:#92400e; --color-info:#1d4ed8;
  --radius-card:16px; --radius-control:10px;
  --shadow-card:0 1px 2px rgb(20 23 28/.04),0 4px 12px rgb(20 23 28/.04);
}
.dark { --color-ink:#edeff3; --color-bg:#0e1116; --color-surface:#161a21; --color-border:#262c36; --color-border-strong:#3a4250;
  --color-text:#edeff3; --color-text-secondary:#9aa3b2; --color-success:#3dd598; --color-error:#f97066; --color-warning:#fdb022; --color-info:#7aa2ff; }
```
Add JetBrains Mono beside Instrument Sans in `vite.config.js` `fonts`. Visual language: soft cards (16 px radius, hairline borders), muted secondary text, dark-ink primary buttons, green only for success/progress, sentence-case labels, Phosphor icons as inline SVG Blade components, state never conveyed by color alone, respect `prefers-reduced-motion`.

**Signature component `<x-ui.stepper :title :steps>`:** card with title left and “N of M” counter + 4–6 px progress bar (ink fill) right; vertical steps with 24 px node, 2 px connector (success when done else border-strong), label (text) and muted detail line. Node states: `done` = filled success circle + white check; `active` = 2 px info ring with spinning 270° arc (1.2 s linear, disabled under reduced motion); `pending` = empty border-strong ring; `failed` = filled error + ×; `skipped` = dashed ring. Used on Job detail (Queued → Provisioning → Running → Completed), runner drawer, host enrollment, GitHub connect, getting-started checklist.

## Data model (PostgreSQL; create as Laravel migrations; UUIDv7 keys via `HasUuids`)

```sql
users(id uuid pk, name, email unique, password null, github_id bigint unique null, is_admin bool default false, two_factor_secret, two_factor_recovery_codes, two_factor_confirmed_at, email_verified_at, remember_token, timestamps, deleted_at)
teams(id, name, slug unique, timestamps, deleted_at)
team_user(team_id fk, user_id fk, role in ('admin','operator','viewer'), pk(team_id,user_id))
team_grants(id, team_id fk, grantable_type, grantable_id uuid, unique(team_id,grantable_type,grantable_id))   -- installation | pool
github_apps(id, app_id bigint, slug, client_id, client_secret enc, private_key enc, webhook_secret enc, api_base_url default 'https://api.github.com', timestamps)
installations(id, github_app_id fk, github_installation_id bigint unique, account_login, account_type, status default 'active', repository_selection, timestamps)
repositories(id, installation_id fk cascade, github_repo_id bigint unique, full_name, private bool, timestamps)  index(installation_id, full_name)
runner_pools(id, installation_id fk, name, scope in ('repository','organization'), scope_target, runner_group_id null, labels text[], image, cpu_limit numeric(5,2) null, memory_limit_mb null,
  min_idle int 0, max_runners int 5, idle_ttl_seconds int 900, docker_mode default 'none', persist_tool_cache bool false, allowed_host_labels text[] '{}', status default 'active',
  timestamps, deleted_at, check(max_runners>=min_idle), unique(installation_id,name)); gin index on labels
hosts(id, name unique, labels text[], status default 'pending', arch, os, docker_version, cpu_total, memory_total_mb, cpu_used, memory_used_mb, disk_free_gb, agent_version, credential_hash, last_seen_at, timestamps, deleted_at)
enrollment_tokens(id, token_hash unique, host_name null, expires_at, used_at null, created_by fk users)
host_commands(id, host_id fk cascade, type, payload jsonb, status default 'pending' (pending|sent|acked|failed), attempts int 0, available_at, acked_at, result jsonb, created_at)  partial index(host_id, available_at) where status in ('pending','sent')
runners(id, pool_id fk, host_id fk null, github_runner_id bigint null, name unique, container_id, state default 'provisioning', exit_reason, exit_code, state_changed_at, started_at, finished_at, timestamps)
  partial indexes (pool_id) and (host_id) where state in ('provisioning','idle','busy')
workflow_jobs(id, provider default 'github', github_job_id bigint unique, github_run_id bigint, repository_id fk, workflow_name, job_name, labels text[], status, conclusion, head_sha, head_branch, actor, html_url,
  pool_id fk null, runner_id fk null, unmatched_reason, queued_at, started_at, completed_at, timestamps)  indexes (status,queued_at), (repository_id,created_at desc), (pool_id,created_at desc)
job_logs(job_id pk fk cascade, storage_path, size_bytes bigint 0, line_count 0, truncated bool false, last_seq bigint 0, finalized_at, expires_at)
webhook_deliveries(id, delivery_id uuid unique, event, action, payload jsonb, status default 'received', error, received_at, processed_at)  index(received_at)
secrets(id, scope_type in (global|installation|pool|registry), scope_id null, name, value enc, timestamps, unique(scope_type,scope_id,name))
audit_logs(id, user_id null fk set null, action, target_type, target_id, metadata jsonb, ip inet, created_at)  index(created_at desc)
personal_access_tokens(id, user_id, name, token_hash unique, role, last_used_at, expires_at null, timestamps)
host_images(host_id, image, status, error, updated_at)
metric_samples(bucket timestamptz, resolution, subject_type, subject_id, queue_depth, runners_busy, runners_idle, queue_wait_p50_ms, queue_wait_p95_ms, jobs_started, jobs_succeeded, jobs_failed, busy_seconds, pk(resolution,subject_type,subject_id,bucket))
settings(key pk, value jsonb), notification_rules, notifications  -- plus Laravel sessions/jobs/failed_jobs
```
Log **bodies** live on the `logs` filesystem disk (chunk files, gzip on finalize), not in Postgres.

## State machines

```php
enum RunnerState: string {
    case Provisioning='provisioning'; case Idle='idle'; case Busy='busy';
    case Terminating='terminating'; case Gone='gone'; case Failed='failed';
    /** @return list<self> allowed next states */
    public function next(): array { return match($this){
        self::Provisioning=>[self::Idle,self::Busy,self::Failed,self::Terminating],
        self::Idle=>[self::Busy,self::Terminating,self::Failed],
        self::Busy=>[self::Terminating,self::Failed],
        self::Terminating=>[self::Gone,self::Failed],
        self::Gone,self::Failed=>[] }; }
}
// TransitionRunner: UPDATE runners SET state=:to,state_changed_at=now() WHERE id=:id AND state IN (:states_that_allow_to)
// 0 rows => stale; return false, no broadcast. JobStatus (queued, in_progress, completed) is monotonic; out-of-order events never regress it.
```

## Key code shapes

```php
// Provisioning (ADR: row-lock, then GitHub, then outbox)
final class ProvisionRunner {
    public function __invoke(RunnerPool $pool, ?WorkflowJob $job=null): ?Runner {
        $runner = DB::transaction(function() use($pool){
            $p = RunnerPool::whereKey($pool->id)->lockForUpdate()->first();
            if ($p->status!=='active' || Runner::live()->where('pool_id',$p->id)->count() >= $p->max_runners) return null;
            return Runner::create(['pool_id'=>$p->id,'name'=>'jogger-'.Str::lower(Str::random(8)),'state'=>RunnerState::Provisioning]);
        });
        if(!$runner) return null;
        try { $jit=$this->github->generateJitConfig($pool,$runner->name,$pool->labels);
              $this->dispatch->start($runner,$jit); }       // writes host_commands row, broadcasts after commit
        catch(Throwable $e){ $this->transition->failed($runner,'provision_error'); throw $e; }
        return $runner;
    }
}
// Outbox
DB::transaction(function() use($host,$spec){ $cmd=HostCommand::create([...]); DB::afterCommit(fn()=>broadcast(new HostCommandAvailable($host->id,$cmd->id))); });
// Webhook pipeline
Route::post('/webhooks/github', GithubWebhookController::class)->middleware([VerifyGithubSignature::class, DeduplicateDelivery::class]);
// Label match (job labels ⊆ pool labels): RunnerPool::query()->whereRaw('labels @> ?::text[]', [$pgArray]) ; rank by specificity (fewest extra labels) then lowest utilization.
// Realtime: events implement ShouldBroadcast+ShouldDispatchAfterCommit; channels in routes/channels.php delegate to policies / visibleTo scopes.
// RBAC: Role enum (viewer<operator<admin); User::roleFor($grantable) = highest role over teams holding a grant; admins (is_admin) see everything.
```
Go agent shape:
```go
type Executor struct{ docker *client.Client; cmds chan Command; results chan Result }
func (e *Executor) Run(ctx context.Context, workers int){ for i:=0;i<workers;i++{ go func(){ for c:=range e.cmds{ e.results<-e.handle(ctx,c) } }() } }
// handlers idempotent per runner id; commands de-duplicated by id; unknown type → ack "unsupported".
// Container HostConfig: SecurityOpt ["no-new-privileges"], CapDrop ["ALL"], PidsLimit 2048, NanoCPUs/Memory from command, per-runner network, AutoRemove, labels jogger.runner/jogger.host.
// Backoff 1s→60s with jitter on all network errors. Credential file 0600. Header X-Jogger-Agent-Protocol: 1.
```

## API and wire protocol

**Web (session):** `/setup`, `/login`, `/two-factor-challenge`, `/forgot-password`, `/auth/github/{redirect,callback}`, `/` (Overview), `/jobs`, `/jobs/{job}`, `/pools`, `/pools/create`, `/pools/{pool}`, `/hosts`, `/runners`, `/github`, `/github/deliveries`, `/github/manifest/callback`, `/secrets`, `/insights`, `/settings/*`, `/admin/{users,teams,audit}`; dev-only `/_ui`. `POST /webhooks/github` (HMAC, CSRF-exempt). `GET /up`, admin `GET /health`, `GET /metrics` (bearer).

**REST `/api/v1`** (personal token, `throttle:api` 120/min): `GET/POST /pools`, `PATCH /pools/{id}`, `GET /jobs`, `GET /jobs/{id}/logs`, `GET /runners`, `GET /hosts`, `POST /hosts/enrollment-tokens` (admin), `GET /me`.

**Agent `/agent/v1`** (bearer host credential except enroll): `POST /enroll`, `POST /broadcasting/auth`, `POST /heartbeat` (every 10 s), `GET /commands`, `POST /commands/{id}/ack`, `POST /events`, `POST /runners/{id}/logs` (`seq` per runner, batched), `GET /version`.

Success: `{ "data": {...}, "meta": {"request_id":"req_…"} }`. Error: `{ "error": {"code":"POOL_LIMIT_EXCEEDED","message":"…","details":{},"request_id":"req_…"} }`.

Command envelope (types `runner.start`, `runner.stop`, `image.pull`, `host.prune`, `agent.update_notice`):
```json
{"id":"uuid","type":"runner.start","protocol":1,"payload":{"runner_id":"uuid","name":"jogger-9f2c","image":"ghcr.io/<org>/jogger-runner:2.x","jit_config":"…","cpu":2.0,"memory_mb":4096,"env":{"K":"V"},"docker_mode":"none","labels":{"jogger.runner":"uuid"}}}
```
Agent events: `container_started`, `container_exited{exit_code,oom}`. Log batch: `{ "seq": 41, "lines": "…", "eof": false }`; duplicate `seq` ignored; gap → 409 with expected seq.

## Auth flows

- **Browser:** Fortify login with throttle 5/min, optional TOTP 2FA + recovery codes, session regeneration. **GitHub sign-in** via Socialite using the GitHub App’s client credentials; link by *verified* email only after password confirmation; unknown identities refused unless invited.
- **API tokens:** random token shown once, store sha256 only, role ability.
- **Agent enrollment:** admin creates token (hash stored, 1 h TTL, single use) → `POST /agent/v1/enroll {token,hostname,facts}` → `{host_id, credential, reverb:{host,port,key}}`; server stores sha256 of credential; agent subscribes to `private-host.{id}` via `/agent/v1/broadcasting/auth`; revoking a host invalidates credential and channel access.
- **Webhook:** `hash_equals` on `X-Hub-Signature-256` with the app’s webhook secret; dedupe by `X-GitHub-Delivery`; respond < 500 ms; process async; store payload 14 days.
- **Roles:** Admin (everything), Operator (pools, images, hosts cordon/drain, runner actions, scoped secrets, logs), Viewer (read-only). Every state-changing action writes an audit row.

## Error handling

| Category | Example | HTTP | Log | User sees |
|----------|---------|------|-----|-----------|
| Validation | Bad label | 422 | debug | field errors |
| Auth | Bad token/signature | 401 | info | Unauthorized |
| Forbidden | Viewer edits pool | 403 | warn | Not authorized |
| Not found | Unknown job | 404 | debug | Not found |
| Business | Pool at max | 409 | info | specific reason |
| Upstream | GitHub 429/5xx | async | warn | banner + retry |
| Host/agent | pull failed | async | error | runner `failed` + reason |
| Internal | exception | 500 | error | request ID |

Domain exceptions extend `JoggerException` with machine codes. Async failures are recorded as state (`runners.exit_reason`, `workflow_jobs.unmatched_reason` e.g. `NO_MATCHING_POOL`, `NO_CAPACITY`) so the UI can explain them.

## Environment variables

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| APP_URL, APP_KEY | yes | — | public URL, encryption key |
| DB_*, REDIS_* | yes | — | PostgreSQL, Redis |
| REVERB_APP_ID/KEY/SECRET/HOST/PORT/SCHEME | yes | — | WebSocket server (also given to agents on enroll) |
| MAIL_* | no | log | email |
| JOGGER_LOG_RETENTION_DAYS | no | 30 | log retention |
| JOGGER_JOB_RETENTION_DAYS | no | 90 | job retention |
| JOGGER_LOG_MAX_BYTES | no | 104857600 | per-job log cap |
| JOGGER_PROVISION_TIMEOUT | no | 180 | seconds before a provisioning runner is reaped |
| JOGGER_HOST_OFFLINE_AFTER | no | 30 | seconds without heartbeat |
| JOGGER_RECONCILE_INTERVAL | no | 60 | seconds |
| JOGGER_METRICS_TOKEN | no | — | `/metrics` bearer |
| JOGGER_LOCAL_HOST | no | false | auto-enroll local Docker socket host |
| JOGGER_RUNNER_IMAGE | no | ghcr.io/<org>/jogger-runner:latest | default image |
| JOGGER_LOG_DISK | no | local | log filesystem disk |
| JOGGER_CACHE_ENABLED | no | false | experimental cache service |

## Implementation order

Each step lists files, behavior, tests. **Checkpoints** after groups: stop and fix before continuing.

### Phase 1 — Foundation
1. **Baseline tooling.** Install packages (above); switch `.env`/`.env.example` to pgsql+redis+reverb; create `config/jogger.php` with every key above; `phpstan.neon` (level 6), `pint.json`, `Makefile` (`dev test lint stan agent-test`), `docker/compose/docker-compose.dev.yml`, pgsql test DB in `phpunit.xml`. *Test:* migrate works; cache round-trip via Redis.
2. **Domain skeleton & support.** Create `app/Domain/*` folders; `HasUuid7`, `JoggerException` family (`PoolAtCapacity`→409 `POOL_LIMIT_EXCEEDED`, `GithubRateLimited`, `HostUnavailable`, `InvalidTransition`), `PgTextArray` cast (commas/quotes safe), JSON error format for `api/*` and `agent/*`, `RequestId` middleware. *Tests:* cast round-trip; 404 JSON shape.
3. **UI shell & primitives.** Tokens above; layouts `app`/`guest` (collapsible sidebar → drawer on mobile, top bar, toast region, theme toggle persisted in localStorage with try/catch); `components/ui/{button,input,select,textarea,checkbox,toggle,modal,dropdown,tabs,toast,badge,card,table,empty-state,stat,tooltip}`; dev-only `/_ui` showcase (404 in production). *Tests:* `/_ui` gating. Criteria: no horizontal scroll at 360 px, modal traps focus.
4. **Stepper + status widgets.** `x-ui.stepper` per spec above, `StepData` DTO, `x-job-status-pill`, `x-runner-state-pill`, `x-host-status-pill`, `x-pool-meter`. *Tests:* 4 steps/2 done → “2 of 4” and 50 % bar; each state has distinct icon + aria-label; unknown value → neutral pill.
5. **Reverb & Echo.** `laravel/reverb`, `config/reverb.php`, `echo.js`, `routes/channels.php` placeholders (deny-all), extend `composer run dev` with `reverb:start`; `Pinged` event with a “Send ping” toast demo on `/_ui`. *Test:* unauthenticated `/broadcasting/auth` → 403.
6. **CI.** `.github/workflows/ci.yml` (pint --test, larastan, phpunit with pgsql+redis services, asset build), dependabot, real README.
**🔍 Checkpoint 1:** `make lint stan test` green; `npm run build` ok; `/_ui` renders light/dark; ping toast arrives in a second tab.

### Phase 2 — Auth & access
7. **Users/teams/roles.** Migrations for users, teams, team_user, team_grants; `Role` enum with `atLeast()`; `VisibleTo` scope trait; `User::roleFor()`; factories. *Tests:* role ordering; grant-based visibility; admin sees all.
8. **Login/reset/2FA.** Fortify provider and config (login, reset, 2FA with confirmation; no registration); Blade guest views; `Settings\Security` Livewire page. *Tests:* throttle 429 on 6th failure; 2FA challenge + recovery code single-use; session regenerated.
9. **First-run setup.** `Setup\FirstRun` (stepper: create admin → URL check → connect GitHub (skippable)); `EnsureSetupComplete`; `CreateInitialAdmin` with race guard. *Tests:* redirect when 0 users; only one admin on concurrent posts.
10. **Policies, audit, API tokens.** `AuditLog` + `Audit::record()`; `PersonalAccessToken` + `AuthenticateApiToken`; `Settings\Tokens`; `routes/api.php` with `GET /api/v1/me`, `throttle:api` 120/min. *Tests:* token shown once/stored hashed; viewer token → 403 on operator endpoint; 121st request → 429.
11. **Users/Teams admin UI.** `Admin\Users`, `Admin\Teams`, invite mail + signed accept route, `ChangeRole`, `GrantTeamAccess`, `DeactivateUser`; last admin cannot be demoted. *Tests:* invite flow; 403 for non-admin.

### Phase 3 — GitHub
12. **GitHub data model.** Migrations/models `GithubApp` (encrypted casts), `Installation`, `Repository`, `WebhookDelivery`; DTOs `InstallationData`, `RepositoryData`, `WorkflowJobData`, `JitConfig`; recorded payload fixtures in `tests/Fixtures/github/`. *Tests:* encryption at rest; DTO mapping; unknown fields ignored.
13. **GitHub gateway.** `GithubGateway` interface + `HttpGithubGateway` (RS256 app JWT 10 min; installation token cached 55 min in Redis; `Http::retry(3, jitter)`; rate-limit headers → `GithubRateLimited` with retry-after); methods `generateJitConfig`, `deleteRunner`, `listRunners`, `listQueuedJobs`, `listInstallationRepositories`, `getRateLimit`; `FakeGithubGateway`. `VERIFY` endpoint paths and permissions first. *Tests:* token reuse; 429 mapping; 5xx retry ×3; JIT for repo and org scope.
14. **Connect GitHub App.** `Github\Connect` stepper (create app → install → pick repos); manifest flow controller (webhook URL `{APP_URL}/webhooks/github`; events `workflow_job`, `installation`, `installation_repositories`, `repository`; permissions: repo Administration r/w, Actions r, Metadata r; org Self-hosted runners r/w; OAuth callback `/auth/github/callback`); manual-credentials fallback; HTTPS/public URL warning. *Tests:* valid code stores encrypted creds; invalid code stores nothing; admin-only.
15. **Webhook endpoint.** `GithubWebhookController`, `VerifyGithubSignature`, `DeduplicateDelivery`, `ProcessWebhookDelivery` job (handler map by event), `Github\Deliveries` UI with payload viewer and Replay, `jogger:prune` for 14-day deliveries. *Tests:* bad signature → 401 and nothing stored; duplicate delivery processed once; 25 MB cap → 413; replay re-dispatches.
16. **Installation/repo sync.** Handlers for `installation`, `installation_repositories`, `repository`; `SyncInstallation`; `Github\Installations` UI with Resync. Idempotent.
17. **Sign in with GitHub.** Socialite controller with dynamic app credentials; link rules as in Auth flows; unlink disabled without a password. *Tests:* verified-email linking requires password; unknown identity refused.
18. **Jobs mirror.** `WorkflowJob`, `JobLog` models/migrations; `JobStatus`, `JobConclusion`; `UpsertJobFromWebhook`, `TransitionJob`; `HandleWorkflowJob` (mirror only); `JobStatusChanged` broadcast (after commit) with channel auth via `visibleTo`. *Tests:* queued→in_progress→completed advances one row; out-of-order `completed` safe; events broadcast once per real transition; unknown repo ignored.
**🔍 Checkpoint 2:** with a real or fixture webhook the Deliveries and mirrored job appear; all tests green.

### Phase 4 — Hosts & Go agent
19. **Hosts, enrollment, agent auth.** `Host`, `EnrollmentToken`, `HostStatus`; `CreateEnrollmentToken`, `EnrollHost`; `routes/agent.php`; `AuthenticateAgent`; `EnrollController`. *Tests:* single-use, 1 h TTL, hashed; reused token → 401 `INVALID_ENROLLMENT_TOKEN`; revoked host rejected.
20. **Heartbeat & Hosts UI.** `HeartbeatController`, `RecordHeartbeat`, scheduled `MarkOfflineHosts` (offline after 30 s), `HostStatusChanged`; `Hosts\Index` + `EnrollModal` (stepper; copy-paste `docker run` command).
21. **Command outbox.** `HostCommand`, `DispatchHostCommand`, `AgentHostDriver` (`HostDriver` interface), `HostCommandAvailable` on `private-host.{id}` after commit, `CommandsController` (fetch marks `sent`, ack → `acked`), `BroadcastAuthController` (own channel only), `RedeliverCommands` (resend un-acked after 30 s, max 5 then `failed`).
22. **Agent scaffold (Go).** `cmd/jogger-agent/main.go`; config (flags > env > `/etc/jogger-agent/config.toml`); enroll (credential 0600); HTTP client with backoff; heartbeat (CPU/mem/disk/Docker version/arch). Binary < 20 MB with `-ldflags "-s -w"`.
23. **Agent commands.** Pusher client (`pusher.go`: connect, private subscribe via `/agent/v1/broadcasting/auth`, ping/pong, reconnect), poller (15 s + on reconnect), dispatcher (worker pool, de-dupe by id, idempotent ack, `unsupported` for unknown). *Tests:* push → fetch within 1 s; polling fallback within 15 s; double delivery executes once.
24. **Agent runner lifecycle.** `runner.start` (pull if missing; per-runner network; container with `JIT_CONFIG`, limits, hardening, labels, AutoRemove), `runner.stop` (graceful then kill, remove network/volumes), events `container_started`/`container_exited{exit_code,oom}`. testcontainers tests with an alpine stand-in: limits applied, OOM flagged, idempotent re-start.
25. **Agent log shipper.** Follow stdout/stderr, batch ≤ 64 KB or 250 ms, `seq` per runner, resend unacked batches, bounded buffer with drop-oldest marker, final `eof`.
26. **Agent housekeeping, DinD, packaging.** Orphan removal (Jogger-labelled, unknown > 10 min), `host.prune`, DinD-rootless sidecar with TLS, preflight `dind_supported`, version check/`--auto-update`, distroless Dockerfile (< 25 MB), cross-compile (amd64, arm64), CI agent job.
27. **Event & log ingestion (server).** `EventsController`, `LogsController`, `ApplyAgentEvent`, `LogIngestor` (dedupe by seq, `SecretMasker`, Redis Stream, broadcast `LogChunkReceived` on `private-job.{id}`), `LogStore` (chunk files, gzip on finalize, cap with truncation marker), `FlushLogs`. *Tests:* duplicate seq ignored; gap → 409 with expected seq; masking; truncation.
28. **Host operations.** `CordonHost`, `DrainHost` (→ cordoned when empty), `RevokeHost`, `EnrollLocalHost` (when `JOGGER_LOCAL_HOST=true`), initial `docker/compose/docker-compose.yml`, `jogger:install`.
**🔍 Checkpoint 3:** from tinker dispatch a `runner.start` with a public test image; the container runs with limits, logs stream to a browser tab, exit event arrives; Go and PHP suites green.

### Phase 5 — Pools & scheduling
29. **Pools.** `RunnerPool` model/migration; `ScalePolicyData`; `CreatePool`/`UpdatePool`/`PausePool`; `Pools\Index` (cards with meters) and `Pools\Form`; `RunnerPoolPolicy`; `/api/v1/pools` resource. Validation: `max_runners ≥ min_idle`, labels `^[A-Za-z0-9._-]{1,64}$`, valid OCI image ref. **Attaching to a public repository requires explicit confirmation warning** (self-hosted runners must not serve untrusted code).
30. **Runner image.** `docker/runner/Dockerfile` (Ubuntu LTS, `ARG RUNNER_VERSION`, git, curl, jq, Docker CLI, build-essential, non-root user), `entrypoint.sh` (`exec ./run.sh --jitconfig "$JIT_CONFIG"`; clear failure without it), `.github/workflows/runner-image.yml` (weekly + manual, amd64+arm64).
31. **Runner model & transitions.** `Runner`, `RunnerState`, `TransitionRunner`, `TerminateRunner` (enqueue `runner.stop` + delete GitHub runner), `RunnerStateChanged`; wire `ApplyAgentEvent`. *Tests:* data-provider over all state pairs; stale event → no-op; exit → gone/failed with reason.
32. **Matching & placement.** `MatchPool` (superset; specificity then utilization; structured no-match reason → `unmatched_reason`), `PickHost` (online, not cordoned, label constraints, free CPU/mem, arch).
33. **ProvisionRunner.** As the code shape above plus `ProvisionRunnerJob` (retry/backoff; release on `GithubRateLimited`); `ResolvesSecrets` null impl. *Tests:* one runner + one `runner.start` per provision; at max → null without GitHub call; JIT failure frees the slot; no capacity → job stays queued with `NO_CAPACITY`.
34. **Webhook → scheduling.** `HandleWorkflowJob`: `queued` → match → provision (ignore jobs without `self-hosted` label); `in_progress` → assign runner, state busy; `completed` → terminate runner; cancelled while queued → remove unused runner ≤ 60 s. *Test:* fake gateway + fake driver full cycle.
35. **Reaper.** `ReapStuckRunners` every 30 s: provisioning > 180 s → failed + job re-queued; host offline > 5 min → `host_lost`; idle beyond `idle_ttl_seconds` above `min_idle` terminated; idempotent.
36. **Warm pool.** `ReconcilePoolCapacity` maintains `min_idle`, never exceeds `max_runners`, queue-based scale-up; paused pools do nothing.
37. **Reconciler.** Every 60 s (Redis single-flight): import queued jobs missing locally; delete unknown Jogger-prefixed GitHub runners; mark runners missing in GitHub > 5 min failed.
38. **Rate-limit lane & invariants.** `RateLimitedByGithub` job middleware (per-installation pause via Redis key). *Tests:* 50 concurrent queued jobs with `max_runners=5` never exceed 5 live runners (20 iterations); 429 pauses/resumes; lost ack redelivered → one container.
39. **Image management.** `EnsureImageOnHosts` (`image.pull`, `host_images` status), per-host pull progress in Pools form, registry credentials via `registry` secrets; prefer hosts with the image.
40. **End-to-end run.** Write `docs/E2E.md` + optional `tests/Script/e2e.sh`; run a real `workflow_dispatch` job on a Jogger runner; record queued→running latency (target median < 15 s with pre-pulled image); fix bugs found.
**🔍 Checkpoint 4 (alpha):** a real GitHub job completes green on an ephemeral Jogger container; container and GitHub runner are gone within 30 s; logs retrievable via API.

### Phase 6 — Operations UI
41. **Log retrieval & retention.** Signed scrollback `from=seq`, download (plain/gz), `GET /api/v1/jobs/{id}/logs`, prune expired logs/jobs; visibility by grants.
42. **Jobs index (live).** Filters (repo, workflow, status, pool, host, range) in query string, cursor pagination, Echo row updates, mobile cards. Server time p95 < 500 ms on 10k seeded jobs.
43. **Job detail.** `Jobs\Show` with stepper (Queued → Provisioning → Running → Completed with durations), side panel (repo, run link, branch/SHA, actor, labels, pool, host, runner), `log-terminal.js` (xterm + fit; scrollback then subscribe `private-job.{id}`; reconnect from last seq; search; download; 50k-line limit with “load earlier”).
44. **Runners page.** Live list, terminate (force confirm for busy), drawer with lifecycle stepper, `/api/v1/runners`, `/api/v1/hosts`; viewers get no actions.
45. **Overview.** Tiles (queue depth, running, idle/busy, 24 h success rate), pool utilization, host health, recent failures, getting-started checklist derived from real state; ≤ 12 queries per load; 5 s Redis aggregate cache.
46. **Command palette (⌘K).** Navigation + visibility-scoped search for jobs/repos/pools/hosts; 300 ms debounce.
47. **Pool/Host realtime polish.** Live meters, per-host runners drawer, unmatched-jobs callout grouped by label set with “Create pool for these labels”.
48. **Secrets.** `Secret` model, `StoreSecret`, `ResolveSecretsForPool` (pool > installation > global), `Secrets\Index` (values never shown), injection into `runner.start` env, masking in logs, rotation affects new runners only.
**🔍 Checkpoint 5 (MVP):** operator demo: create pool, run job, watch live stepper + logs, manage runners/hosts/secrets; all suites green.

### Phase 7–9 — Cache, metrics, notifications, settings
49. **Tool-cache volumes & build-cache policy.** Per-pool+host named volume at `/opt/hostedtoolcache` when `persist_tool_cache`; `host.prune` honors a build-cache cap and never removes in-use volumes.
50. **Metrics & Insights.** `MetricSample`, minute/hour rollups, uPlot charts (queue depth, wait p50/p95, utilization, jobs/day, success rate, minutes per repo/pool, host capacity; ranges 1 h/24 h/7 d/30 d; CSV export; text summaries for accessibility).
51. **Prometheus.** `/metrics` with bearer `JOGGER_METRICS_TOKEN` (`hash_equals`); runners by state, jobs by status, wait histogram, host capacity, GitHub rate-limit remaining; parser-validated.
52. **Notifications.** Rules for host offline, pool unable to provision, GitHub auth failure, disk pressure, webhook failures; channels in-app, mail, HMAC-signed webhook; dedupe window; resolution notice; mail failure falls back to in-app.
53. **Settings & audit UI.** `settings` table with config fallback; `Settings\General` (retention, defaults); `Admin\AuditLog` with filters and CSV export; admin-only.
54. **Spike: cache protocol** (`docs/spikes/cache-protocol.md`): capture endpoints used by the current runner and `actions/cache` via a logging proxy; record go/no-go in `docs/DECISIONS.md`.
55. **Cache service (if go; flag `JOGGER_CACHE_ENABLED`).** Repo-isolated entries on a `cache` disk, LRU eviction under a size cap, runner image env override (`ACTIONS_CACHE_URL`/`ACTIONS_RESULTS_URL`); routes absent when flag off.
**🔍 Checkpoint 6:** Insights shows seeded data correctly; `/metrics` parses; alerts fire once per window.

### Phase 10 — Hardening & release
56. **Security hardening.** `SecurityHeaders` (CSP with nonce, HSTS on HTTPS, `X-Frame-Options: DENY`, nosniff); `jogger:rotate-key` re-encrypting all encrypted columns; webhook/agent routes CSRF-exempt but signature/credential-authenticated; `docs/SECURITY.md` (public-repo warning, DinD caveats); `composer audit` / `npm audit --omit=dev` clean of high/critical.
57. **Production packaging.** `docker/app/Dockerfile` (multi-stage; FrankenPHP; role by command: web, `horizon`, `schedule:work`, `reverb:start`), `entrypoint.sh` (migrate under advisory lock), final `docker-compose.yml` (app, horizon, scheduler, reverb, postgres:17, redis:7-alpine, optional caddy and agent), `.env.production.example`, `GET /up` and admin `/health` (DB, Redis, Reverb, queue lag, GitHub auth, hosts online; degraded when Redis stops). Fresh `docker compose up -d` reaches `/setup` in < 90 s; two replicas migrate safely.
58. **Browser smoke & perf.** Dusk: setup→login→dashboard; job status update visible ≤ 2 s; log line visible ≤ 1 s. Seeded benchmark: webhook p95 < 200 ms, Jobs index p95 < 500 ms (record in `docs/PERFORMANCE.md`).
59. **Accessibility/responsive.** axe-core 0 serious/critical on Overview, Jobs, Job detail, Pools, Hosts, Settings; keyboard reachable; skip link; 360 px usable; no color-only status.
60. **Release automation.** `release.yml` on tags `v*`: multi-arch images (app, agent, runner) to GHCR, agent binaries + checksums, changelog; `CHANGELOG.md`, `docs/RELEASING.md`.
61. **Documentation.** README (logo, tagline “Your GitHub Actions runners, on your own hardware.”, light/dark screenshots, “not affiliated with GitHub”), `docs/{INSTALL,CONFIGURATION,GITHUB-APP,AGENT,POOLS,TROUBLESHOOTING,API}.md`, `docs/openapi.yaml` matching routes, `CONTRIBUTING.md`, `LICENSE`.
62. **v1.0 verification.** All suites green; soak: 50 concurrent runners across ≥ 2 hosts for 1 h with no leaked containers/runners; traceability of every requirement to a test or an explicit known-gap list in `docs/RELEASE-CHECKLIST.md`; tag `v1.0.0`.

## Requirements to preserve (acceptance essentials)

- Webhook: respond < 500 ms, async processing, signature + dedupe; replay supported; 14-day retention.
- Provisioning: one job per runner, destroyed afterwards; never exceed `max_runners`; stuck provisioning > 180 s reaped; median queued→running < 15 s with pre-pulled image; warm pools maintain `min_idle`; paused pools do nothing.
- Hosts: outbound-only agents, heartbeat 10 s, offline after 30 s, cordon/drain/revoke, orphan cleanup after 10 min.
- Logs: visible ≤ 1 s, ANSI rendered, secrets masked, 30-day default retention, 100 MB per-job cap with truncation marker, gap-free reconnect via `seq`.
- Security: runner containers unprivileged (`no-new-privileges`, caps dropped, limits, private network); host Docker socket never mounted into runners; secrets encrypted and never rendered; audit every mutation; warn when a pool targets a public repository.
- UI: status updates ≤ 2 s without reload; WCAG 2.1 AA; usable from 360 px; dark/light.
- Performance targets: 50 concurrent runners / 10 hosts / 1,000 jobs per hour; webhook p95 < 200 ms; UI p95 < 500 ms; agent < 50 MB RAM idle.

## Final quality checks

- [ ] `vendor/bin/pint --test`, `vendor/bin/phpstan analyse` (level 6) clean
- [ ] `vendor/bin/phpunit` 0 failures; `cd agent && go vet ./... && go test ./...` 0 failures
- [ ] `npm run build` succeeds; no CSP violations in the console
- [ ] `php artisan migrate:fresh` clean on PostgreSQL; every migration rolls back
- [ ] `docker compose up -d` from a clean checkout reaches `/setup`; agent image < 25 MB; multi-arch builds succeed
- [ ] A real GitHub workflow job runs, streams logs live, and leaves no container or runner behind
- [ ] README documents install, GitHub App setup, agent enrollment, env vars, and API overview
