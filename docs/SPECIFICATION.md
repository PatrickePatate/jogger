# Jogger — Specification

> A self-hosted, Docker-based control plane that runs your GitHub Actions jobs on your own hardware, with a real-time web UI.

## 1. Overview

### 1.1 What Is Jogger?

Jogger is an open-source platform you install with `docker compose`. It connects to GitHub through a GitHub App, listens for `workflow_job` webhooks, and provisions **ephemeral, single-job runners** (the official `actions/runner` binary inside Docker containers) on one or more Docker hosts. When a job finishes, the container is destroyed. Nothing persists between jobs except an optional shared cache.

The problem: GitHub-hosted runners are costly or limited (minutes, CPU, no private network access), while DIY self-hosted runners are long-lived, insecure (state leaks between jobs), hard to scale, and have no visibility. Existing answers are either Kubernetes-only (Actions Runner Controller) or thin scripts with no UI.

Jogger v1 is a **runner fleet manager**. Workflows stay on github.com and are executed by the real GitHub runner, so compatibility is exact. A later phase (v2) adds a **native workflow engine** that executes `.github/workflows` YAML itself for non-GitHub repos.

### 1.2 Target Audience

- Small/medium teams and homelab users running private GitHub repos who want free or cheap CI minutes on their own servers.
- Platform engineers who need runners inside a private network (databases, internal registries, GPUs) with isolation and an audit trail.
- Organizations that want a simple, Docker-only alternative to Kubernetes-based runner controllers.

### 1.3 Key Differentiators

- **Docker-only, no Kubernetes.** One compose file for the control plane, one small agent binary per extra host.
- **Ephemeral by default.** Every job gets a fresh container using GitHub's JIT runner config; no cross-job contamination.
- **Live UI.** Queue, running jobs, runner state, and job logs stream in real time (Reverb).
- **Multi-host with a Go agent.** Static binary, outbound-only connection (no inbound ports on worker hosts).
- **Official runner.** Exact behavior parity with GitHub-hosted workflows (actions, caching protocol, `services:` containers via Docker).

### 1.4 Competitive Landscape

| Feature | Jogger | Actions Runner Controller | myoung34/docker-github-actions-runner | Gitea Actions / act |
|---------|--------|---------------------------|----------------------------------------|---------------------|
| Runs on plain Docker | ✅ | ❌ (Kubernetes) | ✅ | ✅ |
| Ephemeral JIT runners | ✅ | ✅ | ⚠️ manual | n/a |
| Autoscaling from webhooks | ✅ | ✅ | ❌ | n/a |
| Multi-host | ✅ | ✅ | ❌ | ⚠️ |
| Web UI + live logs | ✅ | ❌ | ❌ | ✅ (own forge) |
| Official GitHub runner | ✅ | ✅ | ✅ | ❌ (reimplementation) |
| Teams and roles | ✅ | ❌ | ❌ | ✅ |

## 2. Core Concepts

| Concept | Definition |
|---------|-----------|
| Installation | A GitHub App installation on a user or org account, granting Jogger access to selected repos. |
| Runner Pool | A named configuration describing what runners look like: labels, image, resources, min/max runners, target scope (repo/org), and allowed hosts. |
| Runner | One ephemeral container executing at most one job. Lifecycle: `provisioning → idle → busy → terminating → gone`. |
| Job | A GitHub `workflow_job` mirrored in Jogger: queued, in_progress, completed, with logs and timings. |
| Host | A machine running Docker where runners are started. Either the control plane's local Docker or a remote Host running the Agent. |
| Agent | The Go binary on a Host that connects to the control plane, starts/stops runner containers, and streams logs and metrics. |
| Runner Image | A Docker image containing `actions/runner` plus tooling. Jogger ships default images and supports custom ones. |
| Label Set | The `runs-on` labels a pool serves (for example `self-hosted, linux, x64, jogger-large`). A queued job is routed to a pool whose label set is a superset of the job's labels. |
| Team | A group of users with a role over a set of installations/pools. |
| Scale Policy | Rules controlling min idle, max concurrent runners, idle TTL, and queue-based scale-up for a pool. |
| JIT Config | GitHub's just-in-time runner configuration, a single-use credential passed to the runner at start. |

## 3. Functional Requirements

### 3.1 GitHub Integration

#### 3.1.1 Connect a GitHub App

**User Story:** As an admin, I want to connect Jogger to GitHub via a GitHub App so that it can create runners without long-lived personal tokens.

**Description:** A guided flow uses the GitHub App Manifest flow to create the app (name, webhook URL, permissions, events) in one click, stores its credentials, and lets the admin install it on accounts/orgs. Manual entry of an existing app's credentials is also supported.

**Acceptance Criteria:**
- [ ] Manifest flow creates the app and stores app ID, private key, webhook secret, client ID and secret.
- [ ] Installing the app on an org/user creates an `Installation` with its accessible repositories listed.
- [ ] Installation add/remove/suspend events from GitHub update Jogger within 10 s.
- [ ] Private key and secrets are stored encrypted.

**Edge Cases:**
- Webhook URL not publicly reachable: show a warning and offer a webhook proxy/tunnel guide `[TBD: built-in smee-style relay?]`.
- GitHub Enterprise Server: out of v1 (see non-goals); configurable API base URL is kept in the model.

#### 3.1.2 Receive and Verify Webhooks

**Description:** Jogger exposes a webhook endpoint that verifies `X-Hub-Signature-256`, deduplicates by `X-GitHub-Delivery`, and handles `workflow_job`, `installation`, `installation_repositories`, `repository` events.

**Acceptance Criteria:**
- [ ] Invalid signature returns 401 and is logged, no processing.
- [ ] A redelivered delivery ID is acknowledged but processed once.
- [ ] Endpoint responds in under 500 ms; processing is asynchronous.
- [ ] All deliveries are stored (with payload) for 14 days and visible in the UI with a "replay" action.

#### 3.1.3 Reconcile with GitHub

**Description:** A periodic reconciler compares Jogger's state with GitHub (queued jobs, registered runners) to recover from missed webhooks.

**Acceptance Criteria:**
- [ ] Every 60 s, queued jobs missing from Jogger are imported and scheduled.
- [ ] Runners registered in GitHub with a Jogger-managed prefix but unknown to Jogger are removed.
- [ ] Runners known to Jogger but missing in GitHub for over 5 minutes are marked failed and their containers destroyed.

### 3.2 Runner Pools

#### 3.2.1 Create and Configure Pools

**User Story:** As an operator, I want to define pools with labels, images, resources, and limits so that jobs run on appropriate runners.

**Acceptance Criteria:**
- [ ] A pool has: name, scope (repository / organization), installation, label set, runner image + tag, CPU/memory limits, min idle, max concurrent, idle TTL, allowed hosts, runner group (org scope), enabled flag.
- [ ] Configuration validated (max ≥ min, labels non-empty, image resolvable).
- [ ] Changes apply to new runners only; running runners are never disrupted.
- [ ] Pools can be paused and resumed; paused pools provision nothing.

**Edge Cases:**
- Two pools match one job: pick the pool with the most specific label match, then lowest utilization.
- No pool matches: job remains queued in GitHub; Jogger shows it as "unmatched" with the labels it needs.

#### 3.2.2 Runner Images

**Description:** Jogger provides default images (Ubuntu LTS-based, with Docker CLI, git, Node, common build tools) and allows custom images built from a Dockerfile or pulled from a registry. Images are pre-pulled to Hosts.

**Acceptance Criteria:**
- [ ] Default image updates when GitHub releases a new `actions/runner` (runners must stay within the supported version window).
- [ ] Image pull progress and failures are visible per Host.
- [ ] Private registry credentials are supported.

### 3.3 Scheduling and Autoscaling

#### 3.3.1 Provision Runners on Demand

**Description:** On a `workflow_job` `queued` event, Jogger matches a pool, picks a Host, requests a JIT config from GitHub, and starts a runner container. On `completed`, the runner is removed.

**Acceptance Criteria:**
- [ ] Each runner runs exactly one job (ephemeral) and is destroyed afterwards, including its volumes.
- [ ] Median time from `queued` webhook to runner executing the job is under 15 s with the image pre-pulled.
- [ ] No more than the pool's max concurrent runners exist at any time, even under concurrent webhooks.
- [ ] Min-idle runners are maintained ("warm pool").
- [ ] A runner stuck in `provisioning` for over 3 minutes is killed and rescheduled.
- [ ] If a job is cancelled before pick-up, any unused runner is removed within 60 s.

**Edge Cases:**
- Host at capacity: try another allowed Host; otherwise queue internally with a visible reason.
- GitHub API rate limit hit: back off using the rate-limit headers; surface a UI warning.
- Container crash mid-job: job fails in GitHub as usual; Jogger records the runner exit reason (OOM, killed, exit code).

#### 3.3.2 Host Selection

**Acceptance Criteria:**
- [ ] Placement considers free CPU/memory (reported by the Agent), pool constraints, and Host labels or taints.
- [ ] A Host can be cordoned (no new runners) and drained (wait for running jobs).

### 3.4 Hosts and Agents

#### 3.4.1 Register a Host

**User Story:** As an admin, I want to add a machine by running one command so that it can run runners.

**Acceptance Criteria:**
- [ ] UI generates a one-time enrollment token and a copy-paste `docker run`/binary command.
- [ ] Agent connects outbound (WebSocket or gRPC stream over TLS); no inbound ports needed on the Host.
- [ ] Agent reports Docker version, CPU, memory, disk, and architecture (x64/arm64), and heartbeats every 10 s.
- [ ] A Host missing heartbeats for 30 s is marked offline; its runners are reconciled when it returns or failed after 5 minutes.
- [ ] Revoking a Host invalidates its credentials immediately.
- [ ] The control plane's local Docker socket can be registered as a Host (optional, off by default).

#### 3.4.2 Agent Operations

**Description:** The agent creates, starts, stops, and removes containers; applies resource limits; streams runner stdout/stderr; collects per-container CPU/memory; cleans up orphans and dangling images/volumes.

**Acceptance Criteria:**
- [ ] Agent upgrades are announced by the control plane; the agent self-updates or flags "update available".
- [ ] Agent refuses commands not signed or authorized by its control plane.
- [ ] Orphaned Jogger-labelled containers (unknown to the control plane) are removed after 10 minutes.

### 3.5 Jobs and Logs

#### 3.5.1 Job Dashboard

**Description:** A live list of jobs across states with filters (repo, workflow, status, pool, Host, time range).

**Acceptance Criteria:**
- [ ] Job status changes appear in the UI within 2 s of the webhook, without reload.
- [ ] Each job shows: repo, workflow, run link, branch/SHA, actor, labels, pool, Host, queue time, start, duration, conclusion.
- [ ] Links to the run on GitHub.

#### 3.5.2 Live Logs

**Description:** The runner's output (including runner diagnostics and the Agent's container logs) streams to the UI in real time and is retained after completion.

**Acceptance Criteria:**
- [ ] Log lines appear within 1 s of being emitted.
- [ ] Logs are stored compressed, with a configurable retention (default 30 days).
- [ ] ANSI colors render, and secrets known to Jogger are masked.
- [ ] Logs can be downloaded as text and searched in-page.

**Constraints:** Log storage per job capped at 100 MB (configurable); excess is truncated with a marker.

### 3.6 Secrets and Environment

**Description:** Admins can define Jogger-level environment variables and secrets injected into runner containers (for example registry credentials, proxy settings), scoped to global, installation, or pool. GitHub-side secrets continue to be managed in GitHub.

**Acceptance Criteria:**
- [ ] Secrets encrypted at rest, never displayed after save, never written to logs.
- [ ] Scope precedence: pool > installation > global.

### 3.7 Caching and Artifacts

**Description:** Optional local cache service (compatible with the GitHub Actions cache API used by `actions/cache`) and a persistent Docker layer/build cache per Host to speed up jobs.

**Acceptance Criteria:**
- [ ] `actions/cache` works against the local cache backend without workflow changes `[TBD: mechanism — see Open Questions]`.
- [ ] Cache has a size cap and LRU eviction per installation.
- [ ] Caches are isolated by repository.

### 3.8 Teams, Users, and Access

#### 3.8.1 Authentication

**Acceptance Criteria:**
- [ ] First-run setup creates the initial admin.
- [ ] Email/password login with optional TOTP 2FA and recovery codes; password reset by email.
- [ ] "Sign in with GitHub" (OAuth via the GitHub App), mapping to users by verified email or explicit link.
- [ ] Login throttling: 5 failed attempts per minute per account/IP.

#### 3.8.2 Roles

| Role | Permissions |
|------|-------------|
| Admin | Everything, including GitHub App, Hosts, users, global settings, secrets. |
| Operator | Manage pools, images, cordon/drain Hosts, cancel/re-run jobs' runners, view logs, manage scoped secrets. |
| Viewer | Read-only access to dashboards, jobs, logs, metrics. |

**Acceptance Criteria:**
- [ ] Roles are assigned per team; a team is granted access to specific installations/pools.
- [ ] Users only see resources their teams grant (admins see all).
- [ ] All state-changing actions write an audit entry (actor, action, target, IP, time).

### 3.9 Metrics and Insights

**Acceptance Criteria:**
- [ ] Dashboard shows: queue depth, queue wait time (p50/p95), runner utilization, jobs per day, success rate, minutes consumed per repo/pool, Host capacity.
- [ ] Time ranges: 1 h, 24 h, 7 d, 30 d.
- [ ] Prometheus-format `/metrics` endpoint (token-protected) for external monitoring.

### 3.10 Notifications

**Acceptance Criteria:**
- [ ] Alerts for: Host offline, pool unable to provision, GitHub API auth failure, disk pressure, webhook failures.
- [ ] Channels: in-app, email, generic webhook `[TBD: Slack/Discord templates in v1.1]`.

### 3.11 Native Workflow Engine (Phase 2 — not in v1.0)

Described in Future Considerations. v1.0 data model and agent protocol must not preclude it (jobs are provider-agnostic: `provider = github`).

## 4. Architecture Overview

### 4.1 System Components

| Component | Responsibility |
|-----------|---------------|
| Web App (Laravel) | UI (Livewire), HTTP API, webhook intake, auth, authorization. |
| Orchestrator | Job matching, scheduling, scale policies, reconciliation (queue workers + scheduler). |
| GitHub Gateway | GitHub App auth (JWT + installation tokens), JIT config, runner/job API calls, rate-limit handling. |
| Realtime Hub (Reverb) | Pushes job, runner, Host, and log events to browsers. |
| Agent Gateway | Accepts Agent connections, authenticates them, dispatches commands, ingests logs/metrics. |
| Agent (Go) | Runs on each Host; manages Docker containers. |
| Data stores | PostgreSQL (state), Redis (queues, cache, locks, pub/sub), object/volume storage for logs. |

### 4.2 Component Interactions

```
GitHub ──webhook──▶ Web App ──enqueue──▶ Orchestrator ──▶ GitHub Gateway (JIT config)
                       │                      │
                       ▼                      ▼
                  PostgreSQL            Agent Gateway ◀──outbound stream── Agent ──▶ Docker
                       │                      │ logs/metrics/state
                       ▼                      ▼
                  Reverb ───────────▶ Browser (Livewire)
```

- Webhook handling is synchronous only for verification and persistence, all else is async via queues.
- Orchestrator decisions are serialized per pool (lock) to enforce limits.
- Agent connections are long-lived and outbound; commands flow down, events flow up.

### 4.3 External Integrations

| Integration | Purpose | If unavailable |
|-------------|---------|----------------|
| GitHub REST API + webhooks | Runner provisioning, job state | Provisioning pauses; reconciler resumes on recovery; UI banner. |
| Docker Engine (per Host) | Runtime | Host marked unhealthy; jobs routed elsewhere. |
| SMTP | Email alerts, resets | Alerts fall back to in-app only. |
| Container registry | Runner images | Use pre-pulled image if present, else fail provisioning with a clear error. |

## 5. Data Model

### 5.1 Core Entities

(All primary keys are UUIDv7 unless noted. All have `created_at`/`updated_at`.)

#### User
| Field | Type | Required | Description |
|-------|------|----------|-------------|
| name, email | string | Yes | Email unique |
| password | string | No | Null when GitHub-only |
| github_id | bigint | No | Linked GitHub identity |
| two_factor_* | encrypted | No | TOTP secret, recovery codes |

#### Team / TeamMember
Team: `name`, `slug`. TeamMember: `team_id`, `user_id`, `role` (admin|operator|viewer).

#### GithubApp
`app_id`, `slug`, `client_id`, `client_secret` (enc), `private_key` (enc), `webhook_secret` (enc), `api_base_url`.

#### Installation
`github_installation_id`, `github_app_id`, `account_login`, `account_type` (user|org), `status` (active|suspended|deleted), `repository_selection`.

#### Repository
`installation_id`, `github_repo_id`, `full_name`, `private`.

#### RunnerPool
| Field | Type | Description |
|-------|------|-------------|
| name | string | Unique per installation |
| installation_id | FK | Source of credentials |
| scope | enum | repository | organization |
| scope_target | string | Repo full name or org login |
| labels | string[] | Label set |
| runner_group_id | bigint | Org scope only |
| image | string | Image ref |
| cpu_limit, memory_limit_mb | int | Per-runner limits |
| min_idle, max_runners | int | Scale policy |
| idle_ttl_seconds | int | Warm runner lifetime |
| allowed_host_labels | string[] | Placement constraint |
| status | enum | active | paused |

#### Host
`name`, `labels`, `status` (pending|online|offline|cordoned|draining|revoked), `arch`, `os`, `docker_version`, `cpu_total`, `memory_total_mb`, `last_seen_at`, `agent_version`, `credential_hash`.

#### Runner
`pool_id`, `host_id`, `github_runner_id`, `name`, `container_id`, `state`, `job_id` (nullable), `started_at`, `finished_at`, `exit_reason`.

#### Job
`provider` (github), `github_job_id`, `github_run_id`, `repository_id`, `workflow_name`, `job_name`, `labels`, `status`, `conclusion`, `head_sha`, `head_branch`, `actor`, `queued_at`, `started_at`, `completed_at`, `pool_id`, `runner_id`.

#### JobLog
`job_id`, `storage_path`, `size_bytes`, `line_count`, `truncated`, `expires_at`.

#### WebhookDelivery
`delivery_id` (unique), `event`, `action`, `payload`, `status`, `received_at`, `processed_at`, `error`.

#### Secret
`scope_type` (global|installation|pool), `scope_id`, `name`, `value` (enc).

#### AuditLog
`user_id`, `action`, `target_type`, `target_id`, `metadata`, `ip`, `created_at`.

#### MetricSample
Rollup rows (pool/host, bucket, queue_wait, runners_busy, etc.) used by dashboards `[TBD: PostgreSQL rollups vs TimescaleDB]`.

### 5.2 Relationships

- GithubApp → has many → Installation → has many → Repository
- Installation → has many → RunnerPool
- RunnerPool → has many → Runner; Host → has many → Runner
- Runner → has one (optional) → Job; Job → has one → JobLog
- Team ↔ User (many-to-many with role); Team ↔ Installation/RunnerPool (many-to-many grants)

### 5.3 Data Lifecycle

- Runners: hard-deleted 7 days after termination (history kept in Job).
- Jobs: retained 90 days (configurable), logs 30 days, webhook deliveries 14 days, audit logs 1 year.
- Users, teams, pools, hosts: soft-deleted; secrets hard-deleted immediately.

## 6. API Surface

### 6.1 API Style

REST + JSON under `/api/v1` for automation and the Agent bootstrap; WebSocket (Reverb) for browser events; a dedicated bidirectional stream for the Agent protocol `[TBD: WebSocket vs gRPC, see Open Questions]`. The UI itself uses Livewire and does not depend on the REST API.

### 6.2 Endpoint Overview

| Method | Path | Description | Auth |
|--------|------|-------------|------|
| POST | /webhooks/github | GitHub webhook intake | HMAC signature |
| GET | /api/v1/pools | List pools | Token |
| POST | /api/v1/pools | Create pool | Token (operator+) |
| PATCH | /api/v1/pools/:id | Update/pause pool | Token (operator+) |
| GET | /api/v1/jobs | List/filter jobs | Token |
| GET | /api/v1/jobs/:id/logs | Download logs | Token |
| GET | /api/v1/runners | List runners | Token |
| GET | /api/v1/hosts | List hosts | Token |
| POST | /api/v1/hosts/enrollment-tokens | Create enrollment token | Token (admin) |
| POST | /api/v1/agent/enroll | Agent enrollment | Enrollment token |
| GET | /metrics | Prometheus metrics | Bearer metrics token |

### 6.3 Authentication & Authorization

- Browser: session cookies. API: personal API tokens (hashed, scoped by role, revocable). Agents: per-Host credential issued at enrollment, rotated on demand.

### 6.4 Rate Limiting

API: 120 req/min per token. Webhook: no per-IP limit (signature-checked) but body capped at 25 MB. Login: see 3.8.1.

### 6.5 Error Format

```json
{ "error": { "code": "POOL_LIMIT_EXCEEDED", "message": "Pool has reached max_runners (10)." } }
```

## 7. User Interface

### 7.1 Interface Type

Web UI built with Livewire 4, Alpine.js, Tailwind CSS 4, and Pines UI components. Live updates via Laravel Reverb. Dark and light themes.

### 7.2 Key Screens

| Screen | Purpose and key elements | Primary actions |
|--------|--------------------------|-----------------|
| Overview | Queue depth, running jobs, pool utilization, Host health, 24 h success rate, recent failures | Jump to jobs/pools |
| Jobs | Live table with filters and status pills | Open job, cancel/re-run on GitHub |
| Job detail | Timeline (queued → provisioning → running → done), live log terminal, runner/Host info | Download/search logs |
| Pools | Cards with utilization bars and warm/busy/idle counts | Create, edit, pause |
| Pool editor | Form with labels, image, limits, scale policy, live validation | Save, test provisioning |
| Hosts | Capacity gauges, status, agent version, running runners | Enroll, cordon, drain, revoke |
| Runners | Live list with state and age | Terminate, inspect logs |
| GitHub | App connection, installations, repos, webhook deliveries | Connect, replay delivery |
| Secrets & Environment | Scoped list (values hidden) | Add, rotate, delete |
| Insights | Charts and per-repo/pool consumption | Change range, export CSV |
| Settings | Retention, notifications, API tokens, branding | Edit |
| Users & Teams | Members, roles, grants, 2FA status | Invite, change role |
| Audit log | Filterable event list | Export |

A command palette (⌘K) offers fast navigation and search across jobs, repos, pools, and Hosts.

### 7.3 Responsive Requirements

Usable from 360 px up; tables degrade to cards on mobile. Layout breakpoints at Tailwind defaults. Target WCAG 2.1 AA: keyboard navigation, focus states, contrast, reduced-motion support, status never conveyed by color alone.

## 8. Security Model

### 8.1 Authentication

Argon2id/bcrypt password hashing, TOTP 2FA, session regeneration on login, GitHub OAuth. See 3.8.1.

### 8.2 Authorization

RBAC with team-scoped resource grants. Policy checks on every Livewire action and API endpoint.

### 8.3 Data Protection

- Secrets, GitHub App key, and Agent credentials encrypted with the app key (rotation supported).
- TLS required for Agent connections and webhooks (provided by the operator's reverse proxy; the compose file ships a Caddy/Traefik example).
- Runner containers: unprivileged, `no-new-privileges`, dropped capabilities, resource limits, isolated per-job network, no host Docker socket mounted by default.
- **Docker-in-job:** workflows needing Docker use a per-runner rootless DinD sidecar (opt-in per pool, `[TBD: sysbox / rootless DinD]`), never the Host socket.
- Agents authenticate with per-Host credentials, communicate outbound only, and only execute the typed commands defined by the protocol (no arbitrary command execution).

### 8.4 Input Validation

Form Requests / Livewire validation for all inputs; image references and labels restricted to safe character sets; webhook payload parsed through typed DTOs.

### 8.5 Threat Notes

- **Public repos and fork PRs:** self-hosted runners must not serve untrusted code. Jogger warns when a pool is attached to a public repository and recommends private repos or approval-required workflow settings.
- Webhook replay and spoofing: HMAC + delivery-ID dedupe.
- Malicious job escaping the container: mitigated by ephemeral containers and hardening above; Host-level isolation is the operator's responsibility for untrusted workloads.

## 9. Deployment Model

### 9.1 Target Environments

Production (self-hosted by the user), staging, and local development (`docker compose` + `composer dev`).

### 9.2 Distribution Method

Published OCI images for the control plane (web, worker, scheduler, reverb) and the agent, plus standalone agent binaries (linux amd64/arm64). A reference `docker-compose.yml` bundles app, queue worker, scheduler, Reverb, PostgreSQL, Redis, and an optional reverse proxy. Releases are semantically versioned.

### 9.3 Configuration

Environment variables for infrastructure (DB, Redis, app URL, mail). Everything operational (GitHub App, pools, retention, alerts) is configured in the UI.

### 9.4 System Requirements

- Control plane: 2 vCPU, 2 GB RAM, 10 GB disk (plus log storage), Docker 24+.
- Hosts: Docker 24+, Linux x64 or arm64, outbound HTTPS to the control plane, github.com, and the registry.

## 10. Performance Requirements

### 10.1 Response Time Targets

- Webhook endpoint: p95 < 200 ms.
- UI page loads: p95 < 500 ms server time.
- Event-to-browser latency (Reverb): p95 < 1 s.

### 10.2 Throughput Targets

- 50 concurrent runners across 10 Hosts, 1,000 jobs/hour, 20 concurrent browser sessions per instance on the reference hardware.
- Sustained log ingestion of 5,000 lines/s total.

### 10.3 Resource Limits

Agent footprint under 50 MB RAM idle. Control plane RAM under 1.5 GB at the target load.

## 11. Constraints & Non-Goals

### 11.1 Technical Constraints

- Laravel 13, PHP 8.3+, PostgreSQL, Redis, Docker. Agent written in Go.
- Linux runners only in v1 (x64, arm64).
- Must use the official `actions/runner` within GitHub's supported version window.

### 11.2 Non-Goals (v1.0)

- **Windows and macOS runners**: different virtualization and licensing; reconsider after v1.
- **Kubernetes backend**: Actions Runner Controller already serves this; a driver interface keeps the door open.
- **GitHub Enterprise Server / GHES support**: base URL is configurable but not tested or supported in v1.
- **Executing workflow YAML natively**: Phase 2 (see section 12).
- **Being a full forge** (repos, PRs, issues): Jogger never hosts code.
- **VM-level isolation (Firecracker/KVM)**: possible future driver.
- **Multi-tenant SaaS billing**: Jogger is single-organization self-hosted.
- **Managing GitHub-side secrets or workflow files**.

### 11.3 Assumptions

- Operators can expose an HTTPS endpoint for webhooks (or use a relay).
- The JIT runner API is available for the plan in use (org/repo-level runners).
- Hosts have outbound internet access.

### 11.4 Open Questions

- **[TBD: Agent protocol]**: WebSocket (reuses Reverb/Laravel stack) vs gRPC (typed, streaming). Leaning toward **WebSocket + JSON/protobuf frames** for operational simplicity behind reverse proxies.
- **[TBD: Cache backend]**: Self-hosted cache compatible with `actions/cache` requires redirecting the runner's cache URL; options are an in-runner env override to a Jogger cache service or documented third-party cache action. Leaning toward the env override on the runner image.
- **[TBD: Log transport]**: Runner container stdout only vs capturing the runner `_diag` logs and job step logs separately. Leaning toward stdout plus tailing `_diag`.
- **[TBD: Metrics storage]**: Plain PostgreSQL rollups vs TimescaleDB. Leaning toward plain rollups for dependency minimalism.
- **[TBD: Docker-in-job]**: Rootless DinD sidecar vs Sysbox runtime.
- **[TBD: Project name/trademark check]**: "Jogger" vs GitHub's "runner" branding rules; avoid implying affiliation with GitHub.

## 12. Future Considerations

- **v1.1**: Slack/Discord notification templates, runner image build pipeline in UI, GPU pools.
- **v1.2**: Kubernetes and cloud-VM (EC2/Hetzner) Host drivers; spot/ephemeral hosts.
- **v2.0**: Native workflow engine — a `provider = jogger` job type, YAML workflow parser, action fetching/caching, matrix and `needs:` graph, and webhook intake from Gitea/GitLab/plain Git. Reuses the Agent, scheduler, logs, and UI.
- **v2.x**: SSO (OIDC/SAML), HA control plane, Windows/macOS Hosts.
