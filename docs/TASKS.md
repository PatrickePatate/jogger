# Jogger — Tasks

> Ordered work breakdown derived from [IMPLEMENTATION.md](IMPLEMENTATION.md) (and [SPECIFICATION.md](SPECIFICATION.md)).
> Execute sequentially. Each task is completable in a single agent session. Paths are relative to the repo root (`/var/www/personnal/jogger`).

**Conventions for every task:** run `vendor/bin/pint` and `vendor/bin/phpunit` (PHP) or `gofmt`/`go test ./...` (agent) before finishing; new PHP files use `declare(strict_types=1)`; no task edits a module owned by a later task except where listed; tests ship with the feature.

## Status (2026-11-10)

| Tasks | State |
|-------|-------|
| 1–39, 41–53, 56, 57, 60, 61 | Done (see [DECISIONS.md](DECISIONS.md) for deviations and what was not verified) |
| 40 | **To do**: the real end-to-end run against GitHub. Procedure and script exist ([E2E.md](E2E.md)); the run itself needs a real GitHub App, repository and public URL |
| 54 | **To do**: cache protocol spike, not performed ([spikes/cache-protocol.md](spikes/cache-protocol.md)) |
| 55 | Deferred to v1.1 (depends on 54) |
| 58 | **To do**: browser tests. Also the place to check the Content-Security-Policy, the log terminal and live updates in a real browser |
| 59 | **To do**: accessibility pass |
| 60 | Done, but the workflow has never run: a pre-release dry run is still needed |
| 61 | Done out of order, before 58–60. Left for later: screenshots in `docs/img/`, and a walkthrough of INSTALL + GITHUB-APP by a new user |
| 62 | **To do**: release checklist and final verification |

## Summary

| Metric | Value |
|--------|-------|
| Total Tasks | 62 |
| Phases | 10 |
| Estimated Effort | ~250–300 hours (≈ 8–10 focused weeks solo) |
| Foundation Complete | After Task 6 |
| First end-to-end job (private alpha) | After Task 40 |
| MVP (usable by a real team) | After Task 48 |
| Full v1.0 Release | After Task 62 |

Existing state: a fresh Laravel 13 skeleton (SQLite, Tailwind 4, Vite 8) is already in the repo; Task 1 builds on it rather than scaffolding anew.

---

## Phase 1: Project Foundation

> After this phase: app boots on PostgreSQL + Redis + Reverb, UI shell and component kit exist, CI is green. Nothing functional yet.

### Task 1: Baseline configuration and tooling

**Switch the skeleton to PostgreSQL/Redis and install core packages and quality tools.**

**Files to create/modify:**
- `composer.json` — add `livewire/livewire:^4`, `laravel/horizon`, `laravel/fortify`, `laravel/socialite`, `firebase/php-jwt`, `spatie/laravel-data`; dev: `larastan/larastan`
- `.env.example`, `.env` — `DB_CONNECTION=pgsql`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `BROADCAST_CONNECTION=reverb`
- `config/jogger.php` — all keys from IMPLEMENTATION.md §8.2 with defaults
- `phpstan.neon` (level 6), `pint.json`, `Makefile` (`dev`, `test`, `lint`, `stan`, `agent-test`)
- `docker/compose/docker-compose.dev.yml` — postgres:17, redis:7-alpine, healthchecks
- `phpunit.xml` — use pgsql test DB (`jogger_test`)

**Commands:**
```bash
composer require livewire/livewire laravel/horizon laravel/fortify laravel/socialite firebase/php-jwt spatie/laravel-data
composer require --dev larastan/larastan
docker compose -f docker/compose/docker-compose.dev.yml up -d
php artisan migrate
```
If Horizon does not resolve for Laravel 13, record it in `docs/DECISIONS.md` and use plain `queue:work` supervisors (IMPLEMENTATION.md §14).

**Acceptance Criteria:**
- [ ] `php artisan migrate` succeeds against PostgreSQL
- [ ] `make lint stan test` passes (0 tests acceptable)
- [ ] `config('jogger.log_retention_days')` returns 30; every key in §8.2 exists
- [ ] Redis reachable: `php artisan tinker --execute="cache()->put('a',1); echo cache('a');"` prints 1

**Dependencies:** None | **Effort:** 3–4 h | **Refs:** IMPL §1, §8, §12.1

---

### Task 2: Domain skeleton, shared support code, exceptions

**Create the `app/Domain/*` module folders and the cross-cutting primitives.**

**Files to create:**
- `app/Domain/{Github,Pools,Runners,Jobs,Hosts,Secrets,Cache,Access,Metrics,Notifications}/.gitkeep`
- `app/Support/Concerns/HasUuid7.php` (wraps `HasUuids`), `app/Support/Exceptions/JoggerException.php` (+ `PoolAtCapacity`, `GithubRateLimited`, `HostUnavailable`, `InvalidTransition`)
- `app/Support/Pg/PgArray.php` — encode/decode Postgres `text[]` for Eloquent (custom cast `PgTextArray`)
- `bootstrap/app.php` — JSON error format `{error:{code,message,details,request_id}}` for `api/*`, `agent/*`; request-ID middleware `app/Http/Middleware/RequestId.php`
- `tests/Unit/PgTextArrayTest.php`, `tests/Feature/ErrorFormatTest.php`

**Acceptance Criteria:**
- [ ] `PgTextArray` round-trips `['self-hosted','linux']` and values containing commas/quotes
- [ ] `GET /api/v1/nope` returns 404 with `error.code=NOT_FOUND` and a `request_id`
- [ ] A thrown `PoolAtCapacity` maps to HTTP 409 `POOL_LIMIT_EXCEEDED`
- [ ] `make test` passes

**Dependencies:** Task 1 | **Effort:** 3 h | **Refs:** IMPL §3.1, §7

---

### Task 3: UI shell, theme tokens, and Pines-derived primitives

**Build the app layout and the reusable component kit.**

**Files to create/modify:**
- `resources/css/app.css` — Tailwind 4 `@theme` tokens (surface, border, ink, accent green, status colors, radius, shadow), dark mode via `class`, tabular numerals utility
- `resources/js/app.js` — Alpine plugins (`focus`, `collapse`), theme toggle persisted in `localStorage` (try/catch)
- `resources/views/layouts/{app,guest}.blade.php` — collapsible sidebar (drawer on mobile), top bar, toast region, theme toggle, user menu slot
- `resources/views/components/ui/{button,input,select,textarea,checkbox,toggle,modal,dropdown,tabs,toast,badge,card,table,empty-state,stat,tooltip}.blade.php`
- `routes/web.php` — dev-only `/_ui` showcase page (gated by `app()->isLocal()`), `resources/views/dev/ui.blade.php`
- `package.json` — `@alpinejs/focus`, `@alpinejs/collapse`

**Pattern:** IMPLEMENTATION.md §6.1/§6.4. Visual direction: soft cards, hairline borders, generous radius, muted secondary text, single green accent (see UI reference note in §6.1).

**Acceptance Criteria:**
- [ ] `npm run build` succeeds
- [ ] `/_ui` renders every primitive in light and dark; keyboard focus rings visible; modal traps focus
- [ ] Layout has no horizontal scroll at 360 px
- [ ] Feature test asserts `/_ui` 404s when `APP_ENV=production`

**Dependencies:** Task 1 | **Effort:** 6–8 h | **Refs:** SPEC §7.3; IMPL §6

---

### Task 4: Stepper, status pills, and live-ready widgets

**Implement the user-provided step-progress card and domain status components.**

**Files to create:**
- `resources/views/components/ui/stepper.blade.php` — props `title`, `steps[]` (`label`, `detail?`, `state: done|active|pending|failed|skipped`), header "N of M" + slim progress bar, vertical connectors, spinning arc for `active`, `prefers-reduced-motion` respected
- `resources/views/components/{job-status-pill,runner-state-pill,host-status-pill,pool-meter}.blade.php`
- `app/Support/Ui/StepData.php` (spatie/laravel-data DTO)
- `resources/views/dev/ui.blade.php` — add stepper examples
- `tests/Feature/StepperRenderTest.php`

**Acceptance Criteria:**
- [ ] Rendering 4 steps with 2 `done` shows "2 of 4" and a 50 % bar (asserted in test)
- [ ] Each state renders distinct icon + `aria-label` (state is not color-only)
- [ ] Spinner animation disabled under `prefers-reduced-motion`
- [ ] Pills cover every enum value defined later via a lookup array (unknown value renders neutral)

**Dependencies:** Task 3 | **Effort:** 3–4 h | **Refs:** IMPL §6.1 UI reference

---

### Task 5: Reverb, Echo, and realtime plumbing

**Install Reverb and wire Echo, channels, and a dev smoke event.**

**Files to create/modify:**
- `composer.json` (`laravel/reverb`), `config/reverb.php`, `config/broadcasting.php`
- `routes/channels.php` — placeholders for `jobs`, `repository.{id}`, `job.{id}`, `host.{id}`, `pool.{id}` (deny-all until policies exist)
- `resources/js/echo.js` (+ import in `app.js`), `package.json` (`laravel-echo`, `pusher-js`)
- `composer.json` scripts — extend `dev` to run `reverb:start`
- `app/Events/Pinged.php`, `tests/Feature/BroadcastAuthTest.php`

**Acceptance Criteria:**
- [ ] `composer run dev` starts web, queue, vite, and reverb
- [ ] In `/_ui`, a "Send ping" button causes a toast in a second open tab within 1 s
- [ ] Unauthenticated `POST /broadcasting/auth` returns 403
- [ ] `make test` passes

**Dependencies:** Task 3 | **Effort:** 3 h | **Refs:** IMPL §6.2, ADR-2

---

### Task 6: CI pipeline (control plane)

**Add GitHub Actions CI for PHP lint/static analysis/tests with PostgreSQL + Redis.**

**Files to create:**
- `.github/workflows/ci.yml` — jobs: `php` (pint --test, larastan, phpunit with services pgsql/redis), `assets` (npm ci, build)
- `.github/dependabot.yml`
- `README.md` — replace Laravel boilerplate with project intro + dev setup (short)

**Acceptance Criteria:**
- [ ] Workflow YAML validates (`actionlint` or `act -n`)
- [ ] Pipeline steps are exactly those of `make lint stan test` plus asset build
- [ ] README describes the 5-command local setup of IMPLEMENTATION.md §12.1

**Dependencies:** Task 1 | **Effort:** 2 h | **Refs:** IMPL §9.3

**Milestone: Foundation complete.**

---

## Phase 2: Auth and Access

> After this phase: first-run setup, login (password + 2FA), roles/teams, API tokens, audit trail.

### Task 7: Users, teams, roles, and grants (data + policies base)

**Files to create/modify:**
- `database/migrations/*_create_users_teams_tables.php` (users per IMPL §4.1 incl. `is_admin`, soft deletes; `teams`, `team_user`, `team_grants`)
- `app/Models/User.php` (modify), `app/Domain/Access/Models/{Team,TeamGrant}.php`, `app/Domain/Access/Enums/Role.php` (`atLeast()`), `app/Domain/Access/Concerns/VisibleTo.php`
- `database/factories/{UserFactory,TeamFactory}.php`
- `tests/Unit/RoleTest.php`, `tests/Feature/VisibilityTest.php`

**Acceptance Criteria:**
- [ ] `Role::Operator->atLeast(Role::Viewer)` true; `Viewer->atLeast(Operator)` false
- [ ] `User::roleFor($grantable)` returns highest role across the user's teams that hold a grant
- [ ] Admin users see all grantables; others only granted ones (feature test with 2 teams)
- [ ] Migrations run and roll back cleanly

**Dependencies:** Task 2 | **Effort:** 4 h | **Refs:** SPEC §3.8, §5.1; IMPL §2.7

---

### Task 8: Login, password reset, and 2FA

**Files to create/modify:**
- `app/Providers/FortifyServiceProvider.php`, `config/fortify.php` (views via Livewire/Blade, features: login, reset, 2FA with confirmation, email verification off)
- `resources/views/auth/{login,two-factor-challenge,forgot-password,reset-password}.blade.php` (guest layout)
- `app/Livewire/Settings/Security.php` + view — enable/confirm/disable 2FA, show recovery codes
- `tests/Feature/Auth/*`

**Acceptance Criteria:**
- [ ] Valid login → dashboard placeholder; invalid → error; 6th failed attempt in 1 min → 429
- [ ] 2FA enable flow requires confirmation code; login then demands TOTP; recovery code works once
- [ ] Password reset email sent (Mail::fake) and token flow works
- [ ] Session ID regenerated on login (asserted)

**Dependencies:** Task 7, Task 3 | **Effort:** 5 h | **Refs:** SPEC §3.8.1

---

### Task 9: First-run setup wizard

**Files to create:**
- `app/Livewire/Setup/FirstRun.php` + view (uses `<x-ui.stepper>`: Create admin → App URL check → Connect GitHub (skippable))
- `app/Http/Middleware/EnsureSetupComplete.php` (register alias, apply to web group)
- `app/Domain/Access/Actions/CreateInitialAdmin.php`, `tests/Feature/SetupTest.php`

**Acceptance Criteria:**
- [ ] With 0 users every route redirects to `/setup`; after creation `/setup` returns 404/redirect
- [ ] Creating the admin logs them in and sets `is_admin=true`
- [ ] Race: two simultaneous setup posts create only one admin (DB lock/unique guard test)
- [ ] App URL mismatch with request host shows a warning (does not block)

**Dependencies:** Task 8, Task 4 | **Effort:** 3 h | **Refs:** SPEC §3.8.1; IMPL §6.3

---

### Task 10: Policies, audit log, API tokens

**Files to create:**
- `app/Domain/Access/Models/AuditLog.php`, `app/Domain/Access/Audit.php` (`Audit::record($action,$target,$meta)` capturing user/IP), migration `audit_logs`
- `app/Domain/Access/Models/PersonalAccessToken.php`, migration, `app/Http/Middleware/AuthenticateApiToken.php` (sha256 lookup, role ability, `last_used_at`)
- `app/Livewire/Settings/Tokens.php` + view (create shows token once, revoke)
- `routes/api.php` (`/api/v1` group, `throttle:api` 120/min) + `GET /api/v1/me`
- `tests/Feature/{ApiTokenTest,AuditTest}.php`

**Acceptance Criteria:**
- [ ] Token shown once; DB holds only its hash
- [ ] Viewer-scoped token calling an operator endpoint (test stub) returns 403 `FORBIDDEN`
- [ ] 121st request/minute returns 429 with `Retry-After`
- [ ] Every state-changing Action tested so far writes an `audit_logs` row

**Dependencies:** Task 7 | **Effort:** 5 h | **Refs:** SPEC §3.8.2, §6.3–6.4; IMPL §5.4

---

### Task 11: Users and Teams admin UI

**Files to create:**
- `app/Livewire/Admin/{Users,Teams}.php` + views; actions `InviteUser`, `ChangeRole`, `GrantTeamAccess`, `DeactivateUser`
- Invitation mail + signed accept route; `routes/web.php` entries (`can:admin`)
- `tests/Feature/Admin/*`

**Acceptance Criteria:**
- [ ] Admin invites by email, invitee sets password via signed link, lands in assigned team
- [ ] Role change takes effect on next request; audit entry created
- [ ] Non-admin gets 403 on `/admin/*`
- [ ] Last admin cannot be demoted/deactivated

**Dependencies:** Task 10, Task 3 | **Effort:** 5 h | **Refs:** SPEC §3.8

---

## Phase 3: GitHub Integration

> After this phase: Jogger is connected to GitHub, receives and stores verified webhooks, mirrors installations/repos and jobs.

### Task 12: GitHub data model

**Files to create:**
- Migrations + models: `GithubApp` (encrypted casts), `Installation`, `Repository`, `WebhookDelivery` (IMPL §4.1)
- `app/Domain/Github/Data/{InstallationData,RepositoryData,WorkflowJobData,JitConfig}.php`
- Factories; `tests/Unit/Github/DataTest.php` using fixtures in `tests/Fixtures/github/*.json` (workflow_job queued/in_progress/completed, installation created/deleted)

**Acceptance Criteria:**
- [ ] `private_key`, `client_secret`, `webhook_secret` are encrypted at rest (raw DB value ≠ plaintext)
- [ ] `WorkflowJobData::from($fixture)` maps labels, ids, run/job URLs, action
- [ ] Unknown extra payload fields are ignored without error

**Dependencies:** Task 2 | **Effort:** 4 h | **Refs:** SPEC §5.1; IMPL §4.1

---

### Task 13: GitHub gateway (App auth, tokens, API client)

**Files to create:**
- `app/Domain/Github/Contracts/GithubGateway.php`, `Gateway/HttpGithubGateway.php`, `Gateway/InstallationTokenStore.php`, `Gateway/AppJwt.php`
- Methods: `generateJitConfig`, `deleteRunner`, `listRunners`, `listQueuedJobs`, `listInstallationRepositories`, `getRateLimit`
- `app/Domain/Github/Testing/FakeGithubGateway.php`; binding in `AppServiceProvider`
- `tests/Feature/Github/GatewayTest.php` with `Http::fake()` using recorded responses

**Implementation notes:** RS256 JWT (10 min), installation token cached 55 min in Redis, `Http::retry(3, jitter)`, read `X-RateLimit-*`, throw `GithubRateLimited` with reset time. **Verify endpoint paths/permissions against current GitHub docs** before coding (IMPL §14).

**Acceptance Criteria:**
- [ ] Token fetched once and reused within TTL (assert one token request for two calls)
- [ ] 403 with rate-limit headers → `GithubRateLimited` carrying `retryAfter`
- [ ] `generateJitConfig` returns `JitConfig{runnerId, encodedJitConfig}` for repo and org scopes
- [ ] 5xx retried up to 3 times then raises

**Dependencies:** Task 12 | **Effort:** 6 h | **Refs:** IMPL ADR-3, §2.3

---

### Task 14: Connect GitHub App (manifest flow UI)

**Files to create:**
- `app/Livewire/Github/Connect.php` + view (stepper: Create app → Install on account → Pick repositories)
- `app/Http/Controllers/GithubManifestController.php` (`redirect`, `callback` exchanging the manifest code), `app/Domain/Github/Actions/CreateGithubAppFromManifest.php`
- Manual-credentials form (existing app) with validation by calling the API
- `tests/Feature/Github/ManifestFlowTest.php`

**Manifest:** webhook URL `{APP_URL}/webhooks/github`, events `workflow_job`, `installation`, `installation_repositories`, `repository`; permissions: repo Administration (r/w), Actions (r), Metadata (r); org Self-hosted runners (r/w); OAuth callback `{APP_URL}/auth/github/callback`.

**Acceptance Criteria:**
- [ ] Callback with a valid code stores app id, slug, encrypted key/secrets (HTTP faked)
- [ ] Invalid/expired code shows an error and stores nothing
- [ ] Only admins can access; audit entry written
- [ ] Warning banner shown when `APP_URL` is not HTTPS/public

**Dependencies:** Task 13, Task 9 | **Effort:** 5 h | **Refs:** SPEC §3.1.1

---

### Task 15: Webhook endpoint and delivery log

**Files to create:**
- `app/Http/Controllers/GithubWebhookController.php`, `Middleware/{VerifyGithubSignature,DeduplicateDelivery}.php`
- `app/Domain/Github/Actions/ProcessWebhookDelivery.php` (queued; dispatches by event via handler map, records status/error)
- `app/Livewire/Github/Deliveries.php` + view (filters, payload viewer, **Replay**)
- `routes/web.php` (exclude from CSRF), prune command `jogger:prune` (14-day deliveries)
- `tests/Feature/Github/WebhookTest.php`

**Acceptance Criteria:**
- [ ] Wrong/missing signature → 401, nothing stored
- [ ] Same `X-GitHub-Delivery` twice → 200 both times, processed once
- [ ] Response time < 500 ms in test with queue faked (assert no sync handlers)
- [ ] Replay re-dispatches processing and marks the replay in the UI
- [ ] Payload > 25 MB rejected 413

**Dependencies:** Task 12, Task 5 | **Effort:** 5 h | **Refs:** SPEC §3.1.2; IMPL §2.8

---

### Task 16: Installation and repository sync

**Files to create:**
- `app/Domain/Github/Handlers/{HandleInstallation,HandleInstallationRepositories,HandleRepository}.php`
- `app/Domain/Github/Actions/SyncInstallation.php` (full resync via gateway)
- `app/Livewire/Github/Installations.php` + view (list, repos, status, resync)
- `tests/Feature/Github/InstallationSyncTest.php`

**Acceptance Criteria:**
- [ ] `installation.created` creates Installation + repositories; `deleted` marks `deleted`; `suspend`/`unsuspend` toggle status
- [ ] `installation_repositories.added/removed` updates repositories within one job run
- [ ] Handlers idempotent (same event twice → same DB state)
- [ ] Installations UI shows repos and a "Resync" button

**Dependencies:** Task 15, Task 13 | **Effort:** 4 h | **Refs:** SPEC §3.1.1

---

### Task 17: Sign in with GitHub

**Files to create/modify:**
- `app/Http/Controllers/Auth/GithubLoginController.php` (Socialite using the stored GitHub App client credentials, dynamic config)
- `resources/views/auth/login.blade.php` (button shown only when an app is connected), `app/Livewire/Settings/Security.php` (link/unlink identity)
- `tests/Feature/Auth/GithubLoginTest.php` (Socialite mocked)

**Acceptance Criteria:**
- [ ] Linked `github_id` logs in directly
- [ ] Unlinked identity with a *verified* matching email must confirm by password before linking; unverified email never auto-links
- [ ] Unknown identity is refused unless invited (no open sign-up)
- [ ] Unlink disabled when the account has no password

**Dependencies:** Task 14, Task 8 | **Effort:** 3 h | **Refs:** SPEC §3.8.1; IMPL §5.4

---

### Task 18: Jobs data model and workflow_job mirroring

**Files to create:**
- Migration + model `WorkflowJob`, `JobLog` (IMPL §4.1), enums `JobStatus`, `JobConclusion` with transition table
- `app/Domain/Jobs/Actions/{UpsertJobFromWebhook,TransitionJob}.php`, `app/Domain/Github/Handlers/HandleWorkflowJob.php` (mirror only; scheduling hooks arrive in Task 38)
- `app/Events/JobStatusChanged.php` (ShouldBroadcast, ShouldDispatchAfterCommit) + channel auth in `routes/channels.php` using `visibleTo`
- `tests/Feature/Jobs/MirrorTest.php`

**Acceptance Criteria:**
- [ ] queued → in_progress → completed webhooks create and advance one row with correct timestamps
- [ ] `completed` arriving before `in_progress` (out of order) still ends in `completed`; stale events are no-ops
- [ ] Event broadcast once per real transition (Event::fake)
- [ ] Jobs for repositories unknown to Jogger are ignored and logged

**Dependencies:** Task 16, Task 5, Task 7 | **Effort:** 4–5 h | **Refs:** SPEC §3.5.1; IMPL ADR-5

---

## Phase 4: Hosts and the Go Agent

> After this phase: a Go agent enrolls, receives commands, runs a container with limits, and ships logs/events. Control plane can reliably command it.

### Task 19: Hosts data model, enrollment, agent auth

**Files to create:**
- Migrations/models `Host`, `EnrollmentToken`; enums `HostStatus`
- `app/Domain/Hosts/Actions/{CreateEnrollmentToken,EnrollHost}.php`
- `app/Http/Controllers/Agent/EnrollController.php`, `Middleware/AuthenticateAgent.php`, `routes/agent.php` (prefix `/agent/v1`), `bootstrap/app.php` route registration
- `tests/Feature/Agent/EnrollTest.php`

**Acceptance Criteria:**
- [ ] Token is single-use, expires after 1 h, stored hashed
- [ ] Enroll returns `host_id`, bearer credential, Reverb connection info; DB stores only credential sha256
- [ ] Reused/expired token → 401 `INVALID_ENROLLMENT_TOKEN`
- [ ] Revoked host's credential is rejected on the next request

**Dependencies:** Task 10 | **Effort:** 4 h | **Refs:** SPEC §3.4.1; IMPL §5.4

---

### Task 20: Heartbeat, host status, Hosts UI

**Files to create:**
- `app/Http/Controllers/Agent/HeartbeatController.php`, `app/Domain/Hosts/Actions/RecordHeartbeat.php`, `app/Console/Commands/MarkOfflineHosts.php` (scheduled each 10 s via `schedule:work`), `app/Events/HostStatusChanged.php`
- `app/Livewire/Hosts/{Index,EnrollModal}.php` + views (capacity gauges, status pill, agent version, enrollment stepper: Create token → Run command → Agent connected → Capacity detected)
- `tests/Feature/Hosts/*`

**Acceptance Criteria:**
- [ ] Heartbeat updates capacity facts and `last_seen_at`
- [ ] No heartbeat for 30 s → status `offline`, event broadcast; heartbeat returns it to `online`
- [ ] Enroll modal shows a copy-paste `docker run` command containing the token and server URL
- [ ] Host list updates live when a host connects (Echo)

**Dependencies:** Task 19, Task 4, Task 5 | **Effort:** 5 h | **Refs:** SPEC §3.4.1, §7.2

---

### Task 21: Host command outbox and agent broadcasting auth

**Files to create:**
- Migration/model `HostCommand`; `app/Domain/Hosts/Actions/DispatchHostCommand.php`; `Drivers/AgentHostDriver.php` implementing `Contracts/HostDriver.php`
- `app/Events/HostCommandAvailable.php` (private channel `host.{id}`), `Controllers/Agent/{CommandsController,BroadcastAuthController}.php`
- `app/Console/Commands/RedeliverCommands.php` (resend `sent` but un-acked after 30 s, up to 5 attempts, then `failed`)
- `tests/Feature/Agent/CommandsTest.php`

**Acceptance Criteria:**
- [ ] `GET /agent/v1/commands` returns pending commands ordered by creation and marks them `sent`
- [ ] Ack with result moves to `acked`; unknown/foreign command id → 404
- [ ] Broadcast fires only after DB commit (`afterCommit`)
- [ ] Agent credential can subscribe only to its own `private-host.{id}` channel
- [ ] Redelivery increments attempts and stops at 5

**Dependencies:** Task 20 | **Effort:** 5 h | **Refs:** IMPL ADR-2, §2.5

---

### Task 22: Agent scaffold — config, enroll, heartbeat

**Files to create (Go, `agent/`):**
- `go.mod`, `Makefile`, `cmd/jogger-agent/main.go`
- `internal/config/config.go` (flags > env > `/etc/jogger-agent/config.toml`), `internal/enroll/enroll.go` (store credential 0600), `internal/transport/http.go` (bearer client, `X-Jogger-Agent-Protocol: 1`, backoff 1–60 s + jitter), `internal/telemetry/heartbeat.go` (CPU/mem/disk, Docker version, arch)
- Tests: `config_test.go`, `http_test.go` (httptest server), `heartbeat_test.go`

**Acceptance Criteria:**
- [ ] `go run ./cmd/jogger-agent --server URL --token T` enrolls once, persists credential, then restarts without a token
- [ ] Heartbeat sent every 10 s; failures retried with capped exponential backoff
- [ ] Credential file created with mode 0600
- [ ] `go vet` and `go test ./...` pass; binary < 20 MB (`-ldflags "-s -w"`)

**Dependencies:** Task 20 | **Effort:** 5 h | **Refs:** SPEC §3.4.2; IMPL §3.1 `agent/`

---

### Task 23: Agent — Reverb client and command pipeline

**Files to create:**
- `agent/internal/transport/pusher.go` (connect, `pusher:subscribe` private channel via `/agent/v1/broadcasting/auth`, ping/pong, auto-reconnect), `internal/commands/{types.go,poller.go,dispatcher.go}` (push OR 15 s poll, de-dupe by id, worker pool per IMPL §2.9, idempotent ack)
- Tests with a fake Pusher server and fake handlers

**Acceptance Criteria:**
- [ ] A `HostCommandAvailable` push triggers a fetch within 1 s
- [ ] With the socket down, commands still arrive via polling within 15 s
- [ ] Same command delivered twice executes once
- [ ] Unknown command type is acked `unsupported`

**Dependencies:** Task 21, Task 22 | **Effort:** 6 h | **Refs:** IMPL ADR-2, §2.9

---

### Task 24: Agent — runner container lifecycle and hardening

**Files to create:**
- `agent/internal/docker/{runner.go,network.go,images.go,stats.go}` — handle `runner.start` (pull if missing, create per-runner network, container with `JIT_CONFIG` env, labels `jogger.runner`/`jogger.host`, limits, `no-new-privileges`, `CapDrop ALL`, PidsLimit, AutoRemove), `runner.stop` (graceful, then kill)
- `agent/internal/events/events.go` — emit `container_started`, `container_exited{exit_code,oom}` via `POST /agent/v1/events`
- Integration tests with testcontainers (alpine stand-in image)

**Acceptance Criteria:**
- [ ] Started container has the configured CPU/memory limits and security options (inspected in test)
- [ ] OOM-killed container reports `oom=true`; exit code propagated
- [ ] Stop removes container, network, and anonymous volumes
- [ ] Re-running the same `runner.start` (same runner id) does not create a second container

**Dependencies:** Task 23 | **Effort:** 7 h | **Refs:** IMPL §10.3; SPEC §3.3.1

---

### Task 25: Agent — log shipper

**Files to create:**
- `agent/internal/logs/shipper.go` — follow container stdout/stderr, batch (≤ 64 KB or 250 ms), monotonically increasing `seq` per runner, resend unacked batches on reconnect, bounded buffer with drop-oldest marker
- Tests (`shipper_test.go`)

**Acceptance Criteria:**
- [ ] Output of a looping test container appears in a fake server within 1 s (batch flush)
- [ ] After server downtime, no gaps and no duplicates by `seq`
- [ ] Buffer cap enforced (memory stays under configured limit under 10 MB/s synthetic output)
- [ ] Shipper stops cleanly on runner exit and sends a final `eof` marker

**Dependencies:** Task 24 | **Effort:** 5 h | **Refs:** SPEC §3.5.2

---

### Task 26: Agent — housekeeping, preflight, DinD sidecar, packaging

**Files to create:**
- `agent/internal/docker/{cleanup.go,dind.go}` — orphan removal (Jogger-labelled, unknown to server > 10 min), image/volume prune command (`host.prune`), preflight report (rootless/userns support) in heartbeat
- `agent/internal/update/update.go` — version check against `/agent/v1/version`, log + heartbeat flag (self-update opt-in flag `--auto-update`)
- `docker/agent/Dockerfile` (distroless static), `agent/Makefile` cross-compile targets (amd64, arm64)
- `.github/workflows/ci.yml` — add `agent` job (golangci-lint, go test with Docker)

**Acceptance Criteria:**
- [ ] Orphan test container older than threshold is removed; running managed container untouched
- [ ] With `docker_mode=dind`, runner gets a private network and a TLS-secured dind-rootless sidecar; host socket is not mounted (asserted via inspect)
- [ ] Preflight reports `dind_supported` boolean in heartbeat
- [ ] `docker build -f docker/agent/Dockerfile .` produces an image < 25 MB

**Dependencies:** Task 24 | **Effort:** 7 h | **Refs:** SPEC §3.4.2; IMPL ADR-8

---

### Task 27: Control plane — event and log ingestion

**Files to create:**
- `app/Http/Controllers/Agent/{EventsController,LogsController}.php`, `app/Domain/Hosts/Actions/ApplyAgentEvent.php`
- `app/Domain/Jobs/Logs/{LogIngestor,LogStore,SecretMasker}.php`, `app/Events/LogChunkReceived.php` (broadcast on `private-job.{id}`), `app/Console/Commands/FlushLogs.php`
- `config/filesystems.php` — `logs` disk
- `tests/Feature/Logs/*`

**Acceptance Criteria:**
- [ ] Duplicate `seq` batch is ignored; out-of-order batch is buffered/reordered or rejected with the expected `seq` in the error
- [ ] Known secret values are masked before storage and broadcast
- [ ] Log exceeding `log_max_bytes_per_job` is truncated with a marker line and `truncated=true`
- [ ] `container_exited` events call back into the runner transition action (stubbed until Task 31)
- [ ] Finalize gzips the log and sets `expires_at`

**Dependencies:** Task 21, Task 18 | **Effort:** 6 h | **Refs:** SPEC §3.5.2; IMPL §4.3

---

### Task 28: Host operations — cordon, drain, revoke, local host

**Files to create/modify:**
- `app/Domain/Hosts/Actions/{CordonHost,DrainHost,RevokeHost,EnrollLocalHost}.php`, UI actions in `app/Livewire/Hosts/Index.php`
- `docker/compose/docker-compose.yml` (initial, `agent` service with socket mount under profile `local-host`) and `jogger:install` artisan command (creates local host token when `JOGGER_LOCAL_HOST=true`)
- `tests/Feature/Hosts/OperationsTest.php`

**Acceptance Criteria:**
- [ ] Cordoned Host is excluded by `PickHost` (test in Task 33)
- [ ] Drain moves status to `draining` and to `cordoned` once its last runner is gone
- [ ] Revoke invalidates credentials immediately and disconnects its channel
- [ ] Local host enrolls automatically when enabled; off by default

**Dependencies:** Task 21 | **Effort:** 4 h | **Refs:** SPEC §3.3.2, §3.4.1

**Milestone: Agent protocol complete — a manual `runner.start` via tinker runs a container and streams logs to the browser.**

---

## Phase 5: Pools, Runners, and Scheduling

> After this phase: a `queued` webhook becomes a running ephemeral runner; the runner is torn down after the job; the system self-heals.

### Task 29: Runner pools — model and management UI

**Files to create:**
- Migration/model `RunnerPool` (IMPL §4.1, GIN index on labels), `app/Domain/Pools/Data/ScalePolicyData.php`, `Actions/{CreatePool,UpdatePool,PausePool}.php`
- `app/Livewire/Pools/{Index,Form}.php` + views (cards with `<x-pool-meter>`; form with live validation), policy `RunnerPoolPolicy`
- `GET/POST/PATCH /api/v1/pools` (`app/Http/Controllers/Api/V1/PoolController.php`, resource + request classes)
- `tests/Feature/Pools/*`

**Acceptance Criteria:**
- [ ] Validation: `max_runners >= min_idle`, labels non-empty and match `^[A-Za-z0-9._-]{1,64}$`, image ref valid
- [ ] Attaching a pool to a **public** repository shows a blocking warning requiring explicit confirmation (SPEC §8.5)
- [ ] Paused pool shown paused; API PATCH `status=paused` works for operator, 403 for viewer
- [ ] Pool list filtered by `visibleTo`

**Dependencies:** Task 16, Task 10, Task 4 | **Effort:** 6 h | **Refs:** SPEC §3.2.1

---

### Task 30: Default runner image

**Files to create:**
- `docker/runner/Dockerfile` (Ubuntu LTS, actions/runner pinned via `ARG RUNNER_VERSION`, git, curl, jq, Docker CLI, build-essential, Node/Python via setup actions only), `docker/runner/entrypoint.sh` (`exec ./run.sh --jitconfig "$JIT_CONFIG"`, unsets the variable from the process env first where possible)
- `.github/workflows/runner-image.yml` (weekly + manual, buildx amd64/arm64, tag with runner version)
- `docker/runner/README.md`

**Acceptance Criteria:**
- [ ] Image builds for amd64 and arm64
- [ ] Container started without `JIT_CONFIG` exits non-zero with a clear message
- [ ] Runs as non-root user; `docker run --rm image id -u` ≠ 0
- [ ] Image size recorded in README; build workflow passes `actionlint`

**Dependencies:** Task 6 | **Effort:** 4 h | **Refs:** SPEC §3.2.2; IMPL ADR-3

---

### Task 31: Runner model, state machine, and transitions

**Files to create:**
- Migration/model `Runner`, `app/Domain/Runners/Enums/RunnerState.php`, `Actions/{TransitionRunner,TerminateRunner}.php`, `app/Events/RunnerStateChanged.php`
- Wire `ApplyAgentEvent` (Task 27) to `TransitionRunner`
- `tests/Unit/RunnerStateTest.php`, `tests/Feature/Runners/TransitionTest.php`

**Acceptance Criteria:**
- [ ] Transition table equals IMPLEMENTATION.md §2.2 (data-provider test over all state pairs)
- [ ] Optimistic `UPDATE … WHERE state IN (…)`: stale event affects 0 rows, no exception, no broadcast
- [ ] `container_exited` moves runner to `gone`/`failed` with `exit_reason` and `exit_code`
- [ ] `TerminateRunner` enqueues `runner.stop` and deletes the runner in GitHub (gateway fake asserted)

**Dependencies:** Task 27, Task 29 | **Effort:** 5 h | **Refs:** IMPL ADR-5

---

### Task 32: Pool matching and host placement

**Files to create:**
- `app/Domain/Pools/Actions/MatchPool.php` (superset match; rank by specificity, then lowest utilization; returns reason when none), `app/Domain/Hosts/Actions/PickHost.php` (online, not cordoned, label constraints, enough free CPU/mem, arch from labels)
- `tests/Unit/{MatchPoolTest,PickHostTest}.php`

**Acceptance Criteria:**
- [ ] Job labels `[self-hosted, linux, x64]` match a pool with a superset; not a pool missing `x64`
- [ ] Two matching pools → more specific wins; tie → lower utilization wins
- [ ] No match returns structured reason listing labels needed (stored as `unmatched_reason`)
- [ ] `PickHost` skips offline/cordoned/insufficient-capacity hosts and honors `allowed_host_labels`

**Dependencies:** Task 29, Task 28 | **Effort:** 4 h | **Refs:** SPEC §3.2.1, §3.3.2

---

### Task 33: Provision runner (reserve, JIT, command)

**Files to create:**
- `app/Domain/Runners/Actions/ProvisionRunner.php` (transaction + `FOR UPDATE` on pool row, count live runners, insert `provisioning`, then gateway JIT, then `DispatchHostCommand('runner.start')`), `app/Jobs/ProvisionRunnerJob.php` (retry/backoff, releases on `GithubRateLimited`)
- `app/Domain/Secrets/Contracts/ResolvesSecrets.php` (null implementation for now)
- `tests/Feature/Runners/ProvisionTest.php`

**Acceptance Criteria:**
- [ ] Provisioning inserts exactly one runner row and one `runner.start` command with image, limits, env, labels
- [ ] At `max_runners` returns null without calling GitHub
- [ ] JIT failure after reserve marks runner `failed` and frees the slot
- [ ] No Host available → job stays queued with `unmatched_reason` (`NO_CAPACITY`) and retries

**Dependencies:** Task 31, Task 32, Task 13 | **Effort:** 6 h | **Refs:** IMPL §2.1, ADR-3, ADR-6

---

### Task 34: Wire webhooks to scheduling and teardown

**Files to modify/create:**
- `app/Domain/Github/Handlers/HandleWorkflowJob.php` — `queued` → MatchPool → ProvisionRunnerJob; `in_progress` → assign runner by runner name/id, state `busy`; `completed` → release/terminate runner; cancelled-before-pickup → terminate unused runner within 60 s
- `app/Domain/Jobs/Actions/AssignJobToRunner.php`
- `tests/Feature/Scheduling/EndToEndFakeTest.php` (fake gateway + fake host driver)

**Acceptance Criteria:**
- [ ] Full cycle in test: queued → runner provisioned → in_progress (runner busy, job linked) → completed (runner terminated)
- [ ] Self-hosted job with no matching pool is marked `unmatched` and shown with required labels, no runner created
- [ ] Cancelled while queued → any idle runner created for it is terminated ≤ 60 s (scheduled-time test)
- [ ] Job with `runs-on: ubuntu-latest` (GitHub-hosted labels only) is ignored

**Dependencies:** Task 33, Task 18 | **Effort:** 5 h | **Refs:** SPEC §3.3.1

---

### Task 35: Stuck-runner reaper and timeouts

**Files to create:**
- `app/Domain/Runners/Actions/ReapStuckRunners.php`, `app/Console/Commands/ReapRunners.php` (scheduled every 30 s)
- `tests/Feature/Runners/ReaperTest.php`

**Acceptance Criteria:**
- [ ] Runner `provisioning` > 180 s is stopped, GitHub runner deleted, state `failed (provision_timeout)`, pending job re-queued for provisioning
- [ ] Runner on a Host offline > 5 min → `failed (host_lost)`
- [ ] Idle runner older than pool `idle_ttl_seconds` and above `min_idle` terminated
- [ ] Reaper idempotent when run twice back-to-back

**Dependencies:** Task 34 | **Effort:** 4 h | **Refs:** SPEC §3.3.1, §3.4.1

---

### Task 36: Warm pool and scale policy

**Files to create:**
- `app/Domain/Pools/Actions/ReconcilePoolCapacity.php` (maintain `min_idle`, respect `max_runners`, queue-based scale-up), `app/Console/Commands/ReconcilePools.php`
- `tests/Feature/Pools/WarmPoolTest.php`

**Acceptance Criteria:**
- [ ] Pool with `min_idle=2` converges to 2 idle runners within 2 scheduler ticks
- [ ] A queued job consumes an idle runner (no extra provisioning) and pool re-warms
- [ ] Never exceeds `max_runners` under concurrent reconcile + webhook (see Task 38 invariant test)
- [ ] Paused pool provisions nothing

**Dependencies:** Task 35 | **Effort:** 4 h | **Refs:** SPEC §3.3.1

---

### Task 37: Reconciler against GitHub

**Files to create:**
- `app/Domain/Github/Actions/ReconcileJobs.php`, `ReconcileRunners.php`, `app/Console/Commands/Reconcile.php` (every 60 s, single-flight Redis lock)
- `tests/Feature/Github/ReconcileTest.php`

**Acceptance Criteria:**
- [ ] Queued job on GitHub missing locally is imported and scheduled
- [ ] Jogger-prefixed GitHub runner unknown to Jogger is deleted
- [ ] Runner missing in GitHub > 5 min is marked failed and its container stopped
- [ ] Two overlapping reconcile invocations: second exits immediately

**Dependencies:** Task 34 | **Effort:** 4 h | **Refs:** SPEC §3.1.3

---

### Task 38: Rate-limit lane, concurrency invariants, and failure injection tests

**Files to create/modify:**
- `app/Domain/Github/Support/RateLimitedByGithub.php` (job middleware pausing per-installation lane via Redis key), integrate into jobs from Tasks 33/36/37
- `tests/Feature/Scheduling/{ConcurrencyTest,FailureInjectionTest}.php` (parallel processes hitting `ProvisionRunner`; injected GitHub 429/5xx; agent ack loss)

**Acceptance Criteria:**
- [ ] 50 concurrent `queued` jobs with `max_runners=5` never yields more than 5 live runners (run 20 iterations)
- [ ] 429 pauses the lane until reset time; jobs resume automatically
- [ ] Lost `runner.start` ack is redelivered and results in one container (idempotent)
- [ ] UI-facing `unmatched_reason`/`failed` reasons populated in all failure cases

**Dependencies:** Task 36, Task 37 | **Effort:** 6 h | **Refs:** SPEC §3.3.1; IMPL ADR-6

---

### Task 39: Image management

**Files to create:**
- `app/Domain/Pools/Actions/EnsureImageOnHosts.php` (`image.pull` commands, track per-host pull status in `host_images` table), registry credentials via Secrets scope `registry` (stored now, UI in Task 48), Livewire section in Pools form: pull progress per host
- `tests/Feature/Pools/ImagePullTest.php`

**Acceptance Criteria:**
- [ ] Saving a pool with a new image tag queues `image.pull` to allowed hosts
- [ ] Pull failure surfaces per host with the error and blocks no existing runners
- [ ] Provisioning avoids hosts lacking the image when another host has it (falls back to pulling on demand otherwise)

**Dependencies:** Task 33, Task 26 | **Effort:** 4 h | **Refs:** SPEC §3.2.2

---

### Task 40: First real end-to-end run (integration milestone)

**Files to create:**
- `docs/E2E.md` (manual procedure against a real GitHub test repo with a tunnel), `tests/Script/e2e.sh` (optional script: creates workflow dispatch via `gh`, polls Jogger API)
- Bug-fix commits as discovered (list them in `docs/E2E.md`)

**Acceptance Criteria:**
- [ ] A real `workflow_dispatch` job runs on a Jogger-provisioned ephemeral container and completes green on GitHub
- [ ] Container and GitHub runner are gone within 30 s of completion
- [ ] Measured queued→running latency recorded in `docs/E2E.md` (target median < 15 s with image pre-pulled)
- [ ] Logs visible via API `GET /api/v1/jobs/{id}/logs`

**Dependencies:** Tasks 28, 34–39 | **Effort:** 6 h | **Refs:** SPEC §3.3.1 (acceptance), §10

**Milestone: End-to-end alpha — real GitHub jobs run on Jogger.**

---

## Phase 6: Jobs, Logs, and Operations UI

> After this phase: the product *feels* finished — live dashboards, log viewer, runner views, command palette.

### Task 41: Log retrieval, scrollback endpoint, and retention

**Files to create:**
- `app/Http/Controllers/JobLogController.php` (signed scrollback `from=seq`, download, plain/gz), API `GET /api/v1/jobs/{id}/logs`, `jogger:prune` extension (logs by `expires_at`, jobs by retention), `app/Livewire/Jobs/...` none yet
- `tests/Feature/Logs/RetrievalTest.php`

**Acceptance Criteria:**
- [ ] Scrollback returns lines from a given `seq` for running and finalized logs
- [ ] Download returns full text; secrets masked
- [ ] Prune deletes expired log files and DB rows, not jobs younger than retention
- [ ] Viewer role can read logs for granted repos only

**Dependencies:** Task 27 | **Effort:** 4 h | **Refs:** SPEC §3.5.2, §5.3

---

### Task 42: Jobs index (live)

**Files to create:**
- `app/Livewire/Jobs/Index.php` + view — filters (repo, workflow, status, pool, host, time range), cursor pagination, status pills, queue/duration columns, `#[On('echo-private:jobs,…')]` row updates; empty/loading states; mobile card layout
- `tests/Feature/Livewire/JobsIndexTest.php`

**Acceptance Criteria:**
- [ ] A status change appears without reload within 2 s (browser smoke in Task 58; Livewire event test here)
- [ ] Filters combine and persist in the query string
- [ ] Only jobs visible to the user are listed (team grants)
- [ ] 10k jobs list renders first page < 500 ms server time (seeded benchmark, indexed queries)

**Dependencies:** Task 18, Task 4 | **Effort:** 5 h | **Refs:** SPEC §3.5.1

---

### Task 43: Job detail with stepper and live log terminal

**Files to create:**
- `app/Livewire/Jobs/Show.php` + view (stepper: Queued → Provisioning → Running → Completed with durations; side panel: repo, run link, branch/SHA, actor, labels, pool, Host, runner)
- `resources/js/log-terminal.js` (xterm.js + fit addon; load scrollback, subscribe `private-job.{id}`, reconnect from `last_seq`, search, download), `npm` deps `@xterm/xterm`, `@xterm/addon-fit`
- `tests/Feature/Livewire/JobShowTest.php`

**Acceptance Criteria:**
- [ ] Step states and timings update live as events arrive
- [ ] Log lines appear within 1 s; ANSI colors render; scrollback limit 50k lines with "load earlier"
- [ ] Reload mid-job shows no duplicated/missing lines (seq-based merge)
- [ ] Failed provisioning shows `failed` step with reason; GitHub run link opens in new tab

**Dependencies:** Task 41, Task 42 | **Effort:** 7 h | **Refs:** SPEC §3.5.1–3.5.2; IMPL §6.2

---

### Task 44: Runners page and runner actions

**Files to create:**
- `app/Livewire/Runners/Index.php` + view (live list, state pills, age, pool/host, terminate with confirm), runner drawer with lifecycle stepper
- `GET /api/v1/runners`, `GET /api/v1/hosts` controllers/resources
- `tests/Feature/Livewire/RunnersTest.php`, `tests/Feature/Api/RunnersApiTest.php`

**Acceptance Criteria:**
- [ ] Terminate on idle runner executes `TerminateRunner` and writes an audit row; busy runner requires explicit force confirm
- [ ] List reflects state changes live
- [ ] Viewer sees no action buttons; API returns 403 on mutation attempts

**Dependencies:** Task 31, Task 42 | **Effort:** 4 h | **Refs:** SPEC §3.3, §7.2

---

### Task 45: Overview dashboard

**Files to create:**
- `app/Livewire/Overview/Dashboard.php` + view — stat tiles (queue depth, running jobs, idle/busy runners, 24 h success rate), pool utilization bars, Host health, recent failures, getting-started checklist (stepper) until complete; 5 s Redis aggregate cache invalidated by events
- `tests/Feature/Livewire/DashboardTest.php`

**Acceptance Criteria:**
- [ ] Tiles reflect seeded data exactly (test with known counts)
- [ ] Checklist steps are derived from real state (admin created, app connected, host online, pool created, first job completed) and hide when all done
- [ ] Dashboard queries are ≤ 12 per load (query-count assertion)

**Dependencies:** Task 44, Task 4 | **Effort:** 5 h | **Refs:** SPEC §7.2

---

### Task 46: Command palette (⌘K)

**Files to create:**
- `app/Livewire/Shared/CommandPalette.php` + view (Alpine modal; navigate pages; search jobs, repos, pools, hosts within the user's visibility), included in `layouts/app.blade.php`
- `tests/Feature/Livewire/CommandPaletteTest.php`

**Acceptance Criteria:**
- [ ] ⌘K / Ctrl+K opens; Esc closes; arrow keys + Enter navigate
- [ ] Search results exclude resources the user cannot see
- [ ] Debounced (300 ms) server search returns < 200 ms for 10k jobs (indexed)

**Dependencies:** Task 45 | **Effort:** 3 h | **Refs:** SPEC §7.2

---

### Task 47: Pool and Host realtime polish

**Files to modify:**
- `app/Livewire/Pools/Index.php`, `app/Livewire/Hosts/Index.php` — live utilization meters (warm/busy/idle), per-host running runners drawer, unmatched-jobs callout on pools page showing labels needed, quick "Create pool for these labels" action
- `tests/Feature/Livewire/*`

**Acceptance Criteria:**
- [ ] Meters update on `RunnerStateChanged` without reload
- [ ] Unmatched jobs list groups by label set and counts occurrences
- [ ] "Create pool for these labels" prefills the form

**Dependencies:** Task 44 | **Effort:** 3 h | **Refs:** SPEC §3.2.1

---

### Task 48: Secrets and environment

**Files to create:**
- Migration/model `Secret`, `app/Domain/Secrets/Actions/{StoreSecret,ResolveSecretsForPool}.php` (precedence pool > installation > global), `app/Livewire/Secrets/Index.php` + view (values never shown, rotate/delete), registry credentials scope for Task 39
- Bind `ResolvesSecrets` into `ProvisionRunner` (env injection) and `SecretMasker`
- `tests/Feature/Secrets/*`

**Acceptance Criteria:**
- [ ] Pool-scope value overrides installation/global with the same name (resolver test)
- [ ] Values encrypted at rest; never rendered or logged; masked in job logs (integration with Task 27)
- [ ] Only operators+ with the pool grant can manage pool secrets; global requires admin
- [ ] Rotation changes apply to new runners only

**Dependencies:** Task 33, Task 27, Task 10 | **Effort:** 5 h | **Refs:** SPEC §3.6

**Milestone: MVP — operators can run, watch, and manage a real runner fleet end to end.**

---

## Phase 7: Caching (v1 scope)

### Task 49: Tool-cache volumes and Host build-cache policy

**Files to modify/create:**
- Pool field `persist_tool_cache` wired through `runner.start` (named volume per pool+host mounted at `/opt/hostedtoolcache`), agent `volumes.go`, Host prune policy settings (max build cache GB) in `host.prune`
- `tests/Feature/Pools/ToolCacheTest.php`, `agent/internal/docker/volumes_test.go`

**Acceptance Criteria:**
- [ ] With the option on, the volume persists across runners of the same pool on a Host; off → no volume
- [ ] Volume is isolated per pool (two pools never share it)
- [ ] `host.prune` honors the build-cache cap and never removes volumes in use

**Dependencies:** Task 26, Task 33 | **Effort:** 4 h | **Refs:** SPEC §3.7; IMPL ADR-7

---

## Phase 8: Metrics, Notifications, Settings

### Task 50: Metrics rollups and Insights page

**Files to create:**
- Migration/model `MetricSample`, `app/Domain/Metrics/Actions/RollupMetrics.php` (minute & hour), scheduled commands, `resources/js/charts.js` (uPlot), `app/Livewire/Insights/Index.php` + view (queue depth, wait p50/p95, utilization, jobs/day, success rate, minutes per repo/pool, Host capacity; ranges 1h/24h/7d/30d; CSV export)
- `tests/Feature/Metrics/*`

**Acceptance Criteria:**
- [ ] Rollup of seeded events yields exact p50/p95 queue wait values
- [ ] Range switch reloads in < 1 s with indexed queries on 30 d of seeded data
- [ ] CSV export matches table values; viewer-scoped to visible repos
- [ ] Charts have accessible text summaries and work in dark mode

**Dependencies:** Task 38, Task 3 | **Effort:** 7 h | **Refs:** SPEC §3.9

---

### Task 51: Prometheus endpoint

**Files to create:**
- `app/Domain/Metrics/PrometheusExporter.php`, `app/Http/Controllers/MetricsController.php`, route `/metrics` (bearer `JOGGER_METRICS_TOKEN`)
- `tests/Feature/Metrics/PrometheusTest.php`

**Acceptance Criteria:**
- [ ] Output passes the Prometheus text-format parser (`promtool check metrics` in CI or a parser unit test)
- [ ] Missing/invalid token → 401; token compared with `hash_equals`
- [ ] Includes runners by state, jobs by status, queue wait histogram, host capacity, GitHub rate-limit remaining

**Dependencies:** Task 50 | **Effort:** 3 h | **Refs:** SPEC §3.9; IMPL §11.4

---

### Task 52: Notifications and alert rules

**Files to create:**
- Migrations/models `NotificationRule`, `app/Domain/Notifications/{Alerts,Channels}/*` (in-app, mail, generic webhook with HMAC), evaluators for: host offline, pool unable to provision, GitHub auth failure, disk pressure, webhook failures; throttling/dedupe window
- `app/Livewire/Settings/Notifications.php` + view, bell menu in layout
- `tests/Feature/Notifications/*`

**Acceptance Criteria:**
- [ ] Each alert type fires on its trigger once per dedupe window (fake clock)
- [ ] Resolution notice sent when the condition clears
- [ ] Mail failure falls back to in-app only and records the error
- [ ] Webhook channel signs payload with `X-Jogger-Signature-256`

**Dependencies:** Task 38, Task 20 | **Effort:** 6 h | **Refs:** SPEC §3.10

---

### Task 53: Settings, retention, and audit log UI

**Files to create:**
- `settings` table/model/`Settings` service with config fallback, `app/Livewire/Settings/General.php` (retention, defaults, branding name), `app/Livewire/Admin/AuditLog.php` (filter, export CSV)
- `tests/Feature/Settings/*`

**Acceptance Criteria:**
- [ ] Changing retention takes effect on the next `jogger:prune`
- [ ] Audit log filters by actor, action, date; CSV export respects filters
- [ ] Settings writes audited; non-admins get 403

**Dependencies:** Task 10, Task 41 | **Effort:** 4 h | **Refs:** SPEC §3.8.2, §5.3

---

## Phase 9: Experimental — Cache Service

> Optional for v1.0; ship behind a flag (`JOGGER_CACHE_ENABLED`). May slip to v1.1 without blocking release.

### Task 54: Research spike — actions cache protocol

**Files to create:** `docs/spikes/cache-protocol.md`

**Description:** Capture the cache API used by the current `actions/cache` + runner (v2 service endpoints, auth token format, upload/download flow) by running a runner against a traffic-logging proxy. Decide implement/defer.

**Acceptance Criteria:**
- [ ] Document lists every endpoint and payload observed, with versions of runner and `actions/cache`
- [ ] Explicit go/no-go recommendation and estimated effort
- [ ] Decision recorded in `docs/DECISIONS.md`

**Dependencies:** Task 40 | **Effort:** 4 h | **Refs:** IMPL ADR-7, §14

---

### Task 55: Cache service implementation (if go)

**Files to create:** `app/Domain/Cache/*` (models, storage on `cache` disk, repo-isolated keys, size cap + LRU eviction job), cache API controllers/routes per the spike, runner image env override (`ACTIONS_CACHE_URL`/`ACTIONS_RESULTS_URL`), tests

**Acceptance Criteria:**
- [ ] A sample workflow using `actions/cache` saves then restores a cache on a later run (E2E doc update)
- [ ] Cache entries isolated per repository; cross-repo read denied
- [ ] Eviction keeps total under the configured cap (LRU test)
- [ ] Feature flag off → no routes registered

**Dependencies:** Task 54 | **Effort:** 8 h (split if exceeded) | **Refs:** SPEC §3.7

---

## Phase 10: Hardening, Packaging, and Release

### Task 56: Security hardening pass

**Files to modify/create:**
- `app/Http/Middleware/SecurityHeaders.php` (CSP with nonce, HSTS when HTTPS, frame/content-type headers), Alpine CSP build check, `app/Console/Commands/RotateAppKey.php` (`jogger:rotate-key` re-encrypts secrets, app key, agent-visible data), rate limits review, `docs/SECURITY.md` (threat notes, public-repo guidance, DinD caveats)
- `tests/Feature/Security/*`

**Acceptance Criteria:**
- [ ] Response headers include CSP and `X-Frame-Options: DENY`; no console CSP violations on all pages (browser check in Task 58)
- [ ] `jogger:rotate-key` re-encrypts every encrypted column and the app works with the new key (round-trip test)
- [ ] Webhook/agent routes reject cross-origin browser requests; CSRF intact elsewhere
- [ ] Larastan level 6 clean; `composer audit` and `npm audit --omit=dev` report no high/critical

**Dependencies:** Task 53 | **Effort:** 6 h | **Refs:** SPEC §8; IMPL §10

---

### Task 57: Production image, compose install, health checks

**Files to create:**
- `docker/app/Dockerfile` (multi-stage; FrankenPHP; roles via command), `docker/app/entrypoint.sh` (migrate under advisory lock, `storage:link`, caches), `docker/compose/docker-compose.yml` (final: app, horizon, scheduler, reverb, postgres, redis, optional caddy + agent), `docker/caddy/Caddyfile`, `.env.production.example`, `docs/INSTALL.md`
- Health: `GET /up`, admin `GET /health` (DB, Redis, Reverb, queue lag, GitHub auth, hosts online)
- `tests/Feature/HealthTest.php`

**Acceptance Criteria:**
- [ ] `docker compose up -d` from a clean checkout yields a working instance reaching `/setup`; startup < 90 s
- [ ] Two simultaneous `app` replicas migrate safely (advisory lock test)
- [ ] Image builds for amd64 and arm64; compressed size recorded
- [ ] `/health` reports degraded status when Redis is stopped

**Dependencies:** Task 56, Task 26 | **Effort:** 7 h | **Refs:** SPEC §9; IMPL §11

---

### Task 58: Browser smoke tests and performance checks

**Files to create:**
- `tests/Browser/{SetupTest,JobLiveUpdateTest,LogStreamTest}.php` (Laravel Dusk), `tests/Perf/seed-benchmark.php`, CI job `browser`

**Acceptance Criteria:**
- [ ] Setup → login → dashboard flow passes headlessly in CI
- [ ] A broadcast job status change updates the Jobs page within 2 s
- [ ] Log lines emitted by a fake runner reach the terminal within 1 s
- [ ] Seeded benchmark: webhook p95 < 200 ms; Jobs index server time p95 < 500 ms (recorded in `docs/PERFORMANCE.md`)

**Dependencies:** Task 43, Task 57 | **Effort:** 6 h | **Refs:** SPEC §10

---

### Task 59: Accessibility and responsive pass

**Files to modify:** all views as needed; `docs/A11Y.md`

**Acceptance Criteria:**
- [ ] axe-core scan (via Dusk) reports 0 serious/critical violations on Overview, Jobs, Job detail, Pools, Hosts, Settings
- [ ] Every interactive element reachable by keyboard; skip-to-content link present
- [ ] All screens usable at 360 px; tables degrade to cards
- [ ] Status is never color-only (icons/text verified)

**Dependencies:** Task 58 | **Effort:** 5 h | **Refs:** SPEC §7.3

---

### Task 60: Release automation

**Files to create:**
- `.github/workflows/release.yml` (on tag `v*`: build/push multi-arch app, agent, and runner images to GHCR; cross-compile agent binaries with checksums; changelog; attach compose bundle), `CHANGELOG.md`, `docs/RELEASING.md`

**Acceptance Criteria:**
- [ ] Dry-run on a pre-release tag publishes images and binaries to a test namespace
- [ ] Agent binary `--version` prints the tag; checksum file verifies
- [ ] Release notes generated from Conventional Commits

**Dependencies:** Task 57 | **Effort:** 4 h | **Refs:** SPEC §9.2

---

### Task 61: Documentation

**Files to create:**
- `README.md` (final), `docs/{INSTALL,CONFIGURATION,GITHUB-APP,AGENT,POOLS,TROUBLESHOOTING,API}.md`, OpenAPI file `docs/openapi.yaml` for `/api/v1`, screenshots in `docs/img/`, `CONTRIBUTING.md`, `LICENSE`

**Acceptance Criteria:**
- [ ] A new user can follow INSTALL + GITHUB-APP to a first green job without outside help (reviewed by walkthrough)
- [ ] `openapi.yaml` validates with a linter and matches implemented routes (test compares route list)
- [ ] Every env var in `.env.production.example` is documented

**Dependencies:** Task 57 | **Effort:** 6 h | **Refs:** SPEC §9

---

### Task 62: v1.0 release checklist and final verification

**Files to create:** `docs/RELEASE-CHECKLIST.md`; fixes found along the way.

**Acceptance Criteria:**
- [ ] Full test suites (PHP, Go, browser) green on `main`
- [ ] Soak test: 50 concurrent runners across ≥ 2 hosts for 1 hour with no leaked containers/runners (verified via `docker ps` + GitHub API)
- [ ] Every SPEC §3 acceptance criterion traced to a test or manual check (traceability table in the checklist; unmet items listed explicitly as known gaps)
- [ ] `v1.0.0` tag produces published artifacts (Task 60)

**Dependencies:** Tasks 55 (or explicitly deferred), 59, 60, 61 | **Effort:** 8 h | **Refs:** SPEC §10, §3

**Milestone: v1.0 released.**

---

## Milestones

| Milestone | After Task | What's Achieved | Demo-able? |
|-----------|-----------|-----------------|------------|
| Foundation | 6 | PG/Redis/Reverb, UI kit, CI | UI showcase, ping toast |
| Access | 11 | Setup, login/2FA, teams, tokens, audit | Log in, invite a user |
| GitHub connected | 18 | App connected, verified webhooks, job mirror | Webhook deliveries + jobs appear |
| Agent protocol | 28 | Go agent enrolls, runs containers, ships logs | Start container from tinker, watch logs |
| End-to-end alpha | 40 | Real GitHub job runs on Jogger | Run a workflow on a Jogger runner |
| MVP | 48 | Live dashboards, log viewer, secrets, pool UX | Full operator demo |
| Insights & alerts | 53 | Metrics, Prometheus, notifications, settings | Dashboards + alert |
| Release | 62 | Hardened, packaged, documented | `docker compose up` to green job |

## Dependency Graph

```
T1 ─┬─ T2 ─┬─ T7 ─ T8 ─ T9 ─ T14 ─ T17
    │      │        └─ T10 ─ T11
    │      └─ T12 ─ T13 ─ T14
    ├─ T3 ─ T4        T12 ─ T15 ─ T16 ─ T18 ─────────────┐
    │   └─ T5 ──────────────┘                              │
    └─ T6                                                  │
T10 ─ T19 ─ T20 ─ T21 ─ T22 ─ T23 ─ T24 ─ T25            │
                         └─ T27 ◀── T18                    │
                              T24 ─ T26                    │
T16+T10 ─ T29 ─ T31 ─ T32 ─ T33 ─ T34 ─ T35 ─ T36 ─ T37 ─ T38 ─ T40
T30 ──────────────────────────────┘     T39 ──────────────┘
T40 ─ T41 ─ T42 ─ T43 ─ T44 ─ T45 ─ T46 ─ T47 ─ T48 (MVP)
T26 ─ T49;  T38 ─ T50 ─ T51;  T38 ─ T52;  T41 ─ T53
T40 ─ T54 ─ T55 (optional)
T53 ─ T56 ─ T57 ─ T58 ─ T59 ;  T57 ─ T60 ─ T62 ;  T57 ─ T61 ─ T62
```

## Parallelization Notes

- The Go agent track (T22–T26) can proceed in parallel with Phase 3 (T12–T18) once T20–T21 (control-plane endpoints) are done.
- UI polish tasks T46/T47 and observability tasks T50–T52 are independent after T40.
- Keep one writer per file set: T27, T31, and T33 all touch `ApplyAgentEvent`/runner actions, so run them sequentially.
