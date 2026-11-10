# Decisions and deviations

Running log of choices made while building, where reality differed from the plan in `IMPLEMENTATION.md`.

## Task 1
- Horizon ^5.50 resolved on Laravel 13 — the plain `queue:work` fallback is not needed.
- Dev Redis is published on host port 6380 (6379 was already taken on the dev machine); Postgres on 5432.
- `jogger_test` is created by `make up`, not by a compose init script (the mount was ignored by the container).

## Task 2
- `HasUuid7` trait deferred to Task 7 (first UUID model); Larastan flags unused traits.

## Task 3
- Alpine `focus` / `collapse` plugins are not installed separately: Livewire 4 already bundles Alpine with them.
- Icons are a small hand-drawn inline SVG set (`<x-icon>`), not Phosphor yet; swap the paths in that one component later.

## Task 5
- `laravel/reverb` ^1.12 requires `guzzlehttp/psr7` ^2.6, but a fresh Laravel 13 install locks psr7 3.x / Guzzle 8. Installed with `composer require laravel/reverb:^1.12 -W`, which downgrades Guzzle 8.3 → 7.16, psr7 3.2 → 2.13, promises 3.1 → 2.6 (all supported by Laravel 13). The alternative was Reverb `dev-main` (unreleased). Revisit when a Reverb release supports psr7 3.
- `php artisan dev` (Laravel 13) auto-registers `reverb:start` and `horizon`, so no change to `composer run dev` was needed.
- Channels `jobs`, `repository.{id}`, `job.{id}`, `host.{id}`, `pool.{id}` are deny-all placeholders until their policies land.
- The dev ping demo uses a public channel (`dev.ping`) because there is no login until Task 8.
- `phpunit.xml` uses `BROADCAST_CONNECTION=reverb` with dummy keys: the `null` broadcaster never enforces channel authorization, so auth tests need the real driver (auth is computed locally; no server needed). Tests that dispatch broadcast events must `Event::fake()` them or they will try to reach Reverb.

## Task 6
- `tests/TestCase.php` calls `withoutVite()`: with no `public/build` (a fresh CI checkout) 4 view tests failed on the missing Vite manifest. PHP tests no longer need a frontend build; the `assets` CI job builds separately.
- CI matrix runs PHP 8.3 (the declared minimum) and 8.5 (the dev machine). The Redis service is provisioned for fidelity, but the test suite uses array cache/session and the sync queue.
- `ci.yml` was validated with actionlint (via stdin: the Docker daemon here cannot bind-mount host paths from `/var/www`, which is also why the Task 1 init-script mount was empty) and by replaying every step in a clean copy of the repo (no `vendor/`, `node_modules/`, `public/build/`, `.env`). It has not yet run on GitHub because the project is not a git repository.
- Dependabot covers composer, npm and github-actions; the Go module and Docker ecosystems are added when `agent/` and the Dockerfiles exist.

## Task 7
- Users moved to UUIDv7 keys by editing the framework's base `create_users_table` migration (pre-release, no data to preserve) instead of adding an alter migration: `users` gained `github_id`, `is_admin`, 2FA columns, soft deletes and a nullable `password`; `sessions.user_id` became a UUID. Existing dev databases need `php artisan migrate:fresh`.
- `is_admin` is a global flag (sees and can do everything); team roles (`viewer < operator < admin`) only apply to resources a team was granted. `User::roleFor()` returns the highest role across non-deleted teams holding the grant, or `Admin` for global admins, or `null`.
- Grants are polymorphic by a string key (`team_grants.grantable_type`, e.g. `pool`, `installation`) defined by `Grantable::grantableType()` (default: snake-cased class name). No Eloquent morph map is needed yet.
- `VisibleTo` is exercised by a test-only `Tests\Support\Widget` model; `tests/Support` is included in the Larastan paths so the trait is analysed (it has no production user until Installation/RunnerPool exist).

## Task 8
- `php artisan fortify:install` scaffolding was trimmed to what the spec needs: no registration (accounts come from first-run setup or invitation), no profile/password-update actions, no passkeys (its migration and config were removed), and its 2FA-columns migration was deleted because Task 7's users table already has them. Enabled features: password reset and two-factor authentication (with confirmation).
- The Security page calls Fortify's 2FA actions directly (enable / confirm / regenerate / disable) and asks for the current password itself, instead of Fortify's `password.confirm` redirect flow. Accounts without a local password (GitHub-only) skip that check. Fortify's own `/user/two-factor-*` routes still exist behind `password.confirm`; a confirm-password view is provided for them.
- Password rule: minimum 10 characters (`Password::defaults`). Login throttle: 5 attempts per minute per email+IP (6th returns 429).
- `/` and `/settings/security` now require authentication; the default example test was changed to assert the redirect.
- Fortify's TOTP replay protection records the *current* window when a code is accepted, so it blocks replays of current/past-window codes. Tests therefore log in with a previous-window code.
- The toast icon rendered blank because Alpine `<template>` elements don't work inside an `<svg>`; each icon is now its own `<template x-if>`.

## Task 9
- "Set up" means *any* user exists, including soft-deleted ones (`Setup::isComplete()`), checked with one `exists()` query per web request. It is deliberately not cached: a cached "true" would survive `migrate:fresh` in dev (Redis) and hide the wizard.
- `EnsureSetupComplete` is *prepended* to the `web` group (Laravel's middleware priority puts `auth` ahead of appended middleware, which sent guests through `/login` first). Exempt on a fresh install: `/setup`, `/livewire*`, `/broadcasting/*`, `/webhooks/*`, `/_ui*`; `/up` is outside the web group.
- Because "after creation `/setup` redirects", the wizard's steps 2 and 3 run in the same page load as step 1 (Livewire state); reloading mid-wizard leaves it. Step 3 ("Connect GitHub") only offers a button once a `github.connect` route exists (Task 14); until then it just finishes.
- `CreateInitialAdmin` serializes with `pg_advisory_xact_lock`. Verified against the dev database with 8 parallel processes: 1 created, 7 refused; the same race without the lock created all 8.
- Livewire requests after step 1 are authorised by session (`Auth::check() && isAdmin()`), steps cannot be skipped (`abort_unless($this->step === n, 403)`), and `step` / `browserOrigin` are `#[Locked]`.
- Tests that touch web routes now create a user first (otherwise everything redirects to `/setup`).

## Task 10
- **Token auth must run before the throttle.** Laravel sorts a fixed list of "priority" middleware, and `ThrottleRequests` is in it while a custom auth middleware is not, so the limiter ran first and keyed every token by IP (one shared bucket). Fixed with `prependToPriorityList(before: ThrottleRequests::class, prepend: AuthenticateApiToken::class)`; found by a test where a second token was throttled by the first token's bucket. Verified live: 120 calls per token, then 429 with `Retry-After`.
- Tokens: `jog_` + 40 random chars, only the sha256 is stored, shown once. A token's `role` is a **ceiling** (`api.role:operator` middleware); the owner's own access still applies. Creation is refused above the owner's own highest role (`User::maxRole()`: global admin → Admin, else highest team role; anyone may create viewer tokens).
- `last_used_at` is written at most once a minute. Expired tokens and soft-deleted owners resolve to 401.
- Audit log is append-only by convention: model `updating` throws; `audit_logs.user_id` is `nullOnDelete` so history survives user deletion; `ip` is a Postgres `inet`; metadata is `jsonb` (key order is not preserved). Audited so far: `setup.admin_created`, `auth.two_factor_setup_started|enabled|disabled`, `auth.recovery_codes_regenerated`, `token.created|revoked`. Every later state-changing Action must call `Audit::record()`; never put secrets in metadata.
- Policies: `PersonalAccessTokenPolicy` (own tokens, or global admin) and a global `admin` gate (`can:admin`) for Task 11.
- Larastan does not read casts from a `casts()` *method* for these models (it typed `expires_at` as string), so `PersonalAccessToken` uses the `$casts` property.
- API routes live in `routes/api.php` under `/api/v1`, outside the `web` group, so the first-run redirect does not apply to them.

## Task 11
- Invitations live in their own `invitations` table (no half-created users): email, optional team + role, `is_admin`, expiry (7 days), `accepted_at`. A partial unique index allows one *open* invitation per email; inviting the same address again updates its terms and re-sends the mail (that is how "Resend" works). Accepting creates the verified user, joins the team (if it still exists; teams are soft-deleted so a deleted team just means no membership) and marks the invitation used under a row lock, so the link works exactly once.
- The accept link is a Laravel *signed* URL built from `APP_URL` (the POST reuses the same signed URL). A host mismatch invalidates the signature, which is exactly what the setup wizard's APP_URL check warns about. Expired or tampered links render a friendly 403 page.
- "Administrator" is the global `is_admin` flag; team roles are separate. The *last administrator* guard (`AdminGuard`) uses a Postgres advisory lock plus a count of other active admins, covering both demotion (`SetAdministrator`) and deactivation (`DeactivateUser`). You cannot deactivate yourself. Deactivation soft-deletes the user (the session stops resolving) and revokes their API tokens; `ReactivateUser` brings them back (added beyond the task list so deactivation is not a dead end).
- Role changes (`ChangeRole`) are plain UPDATEs of `team_user.role`; permissions are always computed from the database, so they apply on the user's next request.
- Admin pages are guarded by `can:admin` on the routes **and** `Gate::authorize('admin')` inside every Livewire action, because Livewire update requests do not go through the original route middleware.
- Modal events: Livewire's `dispatch('close-modal', 'x')` delivers an *array* as the event detail while Alpine's `$dispatch` delivers a string, so a modal stayed open after a successful invite. The modal now accepts both forms and Livewire sends a named `name:` parameter.
- The team page lists grants by type and id; resolving them to names comes with Installations (Task 12/16) and Pools (Task 29).

## Task 12
- **Fixtures are hand-written, not captured deliveries.** `tests/Fixtures/github/*.json` follow GitHub's documented `workflow_job` / `installation` / generate-jitconfig schemas (ids, labels, URLs, a `some_future_field`, extra nested fields) but were not recorded from a live GitHub App: that needs the app connected (Task 14) and a public webhook URL. Replace or add real captures during the end-to-end run (Task 40) and rerun `DataTest`. The docs page for `workflow_job` confirms the "Actions: read" permission; its nested property tables are collapsed in the rendered page, so field names come from the documented schema as I know it.
- DTOs flatten the whole webhook body in `prepareForPipeline` (so `WorkflowJobData::from($payload)` works as the spec says) and *omit* missing keys instead of nulling them, so a payload without essentials raises Spatie's `CannotCreateData` rather than a bare `TypeError`; unknown fields at any level are ignored.
- `JitConfig` holds a secret: `#[SensitiveParameter]` plus a redacting `__debugInfo`, and `GithubApp` hides `client_secret`/`private_key`/`webhook_secret` from serialization. All three are `encrypted` casts (Laravel's authenticated envelope; same plaintext encrypts differently each time).
- `account_type` is stored as `user|org` (GitHub says `User`/`Organization`; `Bot` is treated as `user`). `Installation` implements `Grantable` (type `installation`) so teams can be granted installations (Task 11 grants UI will resolve names from here).
- `webhook_deliveries.github_app_id` (nullable, `nullOnDelete`) was added beyond the plan so deliveries can be attributed to an app and survive its removal.
- Unknown visibility in a repository payload defaults to *private* (fail closed: public repositories trigger the self-hosted-runner warning, so we only say "public" when GitHub says so).

## Task 13
**Verified against docs.github.com while building** (the "VERIFY" item from the plan):
- JIT config: `POST /repos/{owner}/{repo}/actions/runners/generate-jitconfig` needs the repository **Administration (write)** permission; `POST /orgs/{org}/actions/runners/generate-jitconfig` needs the organization **Self-hosted runners (write)** permission. Body: `name`, `runner_group_id` (**required**; we send the scope's group or `1`, the Default group), `labels` (**required**, 1–100). Response: `runner{id,name,os,status,busy,labels[]}` + `encoded_jit_config`.
- Delete runner: repo Administration (write) / org Self-hosted runners (write). List runners: repo Administration (read) / org Self-hosted runners (read), with `name`, `per_page`, `page`. Workflow runs/jobs: **Actions (read)**; `status=queued|in_progress`, jobs `filter=latest`. These match the App manifest planned for Task 14.
- JWT: RS256, `iat` back-dated 60 s, `exp` ≤ 10 minutes, `iss` = the app's **client ID** (docs recommend it over the app ID), sent as `Authorization: Bearer`.
- Rate limits: primary → 403/429 with `x-ratelimit-remaining: 0`, wait until `x-ratelimit-reset`; secondary → 403/429 with a message, wait `retry-after`, else at least a minute. Installations get 5,000 requests/hour (more with many repos/users); POSTs cost 5 secondary-limit points; ≤ 80 content-creating requests/minute.
- **API version:** GitHub now documents `2026-03-10`; requests without the header default to `2022-11-28`, supported until March 2028. We pin `2022-11-28` (`JOGGER_GITHUB_API_VERSION`) because I have not reviewed the breaking-changes list for the newer version. Revisit before 2028.

**Design choices**
- The gateway takes a `RunnerScope` (installation + repository|organization + target + optional runner group) instead of a `RunnerPool`, since pools do not exist until Task 29; pools will build the scope. Repo/org names are validated before being put in URL paths.
- "Retried up to 3 times" = the first attempt plus **3 retries** (4 requests), only for connection errors and 5xx, with exponential backoff and jitter (`JOGGER_GITHUB_RETRY_MS`, 0 in tests). 4xx responses and rate limits are never retried.
- Rate limits throw `GithubRateLimited` with the delay and **pause the installation** (`RateLimitTracker`, cached): further calls fail fast without touching GitHub until the pause ends. Task 38 builds the queue-lane middleware on `pausedUntil()`. Remaining quota from response headers is cached too.
- A `401` means the cached installation token was revoked or expired early: it is replaced and the call retried once. Tokens are cached encrypted (≈55 min, or less if GitHub says so, minus a 5-minute margin) under a cache lock so concurrent workers mint once (needs a lock-capable store: Redis is).
- `deleteRunner` treats 404 as success. `listQueuedJobs` has no direct GitHub endpoint, so it lists `queued` and `in_progress` runs (first 100 each) and their `queued` jobs: cost is 2 + N requests per repository, which the reconciler (Task 37) must budget.
- Bug found by the tests: `acceptJson()` followed by an `Accept` header produced *two* `Accept` values; the client now sets only `application/vnd.github+json`.
- `FakeGithubGateway::install()` binds an in-memory gateway (records calls, predictable JIT configs, `failNextWith()`); it lives in `app/` so later feature tests can reuse it.
- API-response fixtures in `tests/Fixtures/github/` are, like the webhook ones, written to the documented schema rather than recorded from a live app.

## Task 14
**Verified against docs.github.com:**
- Manifest flow: POST a form with a JSON `manifest` field (and a `state` query parameter) to `https://github.com/settings/apps/new` (personal) or `https://github.com/organizations/{org}/settings/apps/new`; GitHub redirects to `redirect_url` with `?code=…&state=…`; the code (valid one hour) is exchanged with `POST /app-manifests/{code}/conversions` (no credentials), which returns `id`, `slug`, `client_id`, `client_secret`, `webhook_secret` and `pem` (private key). Manifest keys used: `name`, `url`, `description`, `public`, `hook_attributes`, `redirect_url`, `callback_urls`, `setup_url`, `setup_on_update`, `request_oauth_on_install`, `default_events`, `default_permissions`.
- **Correction to the plan:** `installation` and `installation_repositories` "cannot be manually subscribed to": every GitHub App receives them automatically. The manifest therefore requests only `workflow_job` and `repository` (the latter needs Metadata read). The webhook handlers for installation events (Task 16) are still needed.
- `GET /app` (signed with the app JWT) returns the authenticated app (`id`, `slug`): used to prove manually entered credentials before saving anything.

**NOT verified:** the permission *parameter names* in `default_permissions`. The docs page I could render lists display names ("Administration", "Self-hosted runners") but not the manifest keys, and GitHub's OpenAPI file was blocked from the browser. I used `actions`, `administration`, `metadata` (certain) and **`organization_self_hosted_runners`** (from memory of GitHub's `app-permissions` schema; high confidence, not confirmed). If GitHub rejects the manifest, that key is the first suspect; the "I already have an app" tab is the fallback. Confirm on the first real run (Task 40). The same goes for the whole flow: it has never talked to real GitHub; only the redirect output and the code exchange (faked) were exercised.

**Design:**
- State: a random 40-char `state` is stored in the session with a 55-minute expiry and **consumed on first use**; the callback refuses a missing, wrong, expired or reused state *before* calling GitHub (a test disables the check to prove the tests would catch its removal). The one-time `code` is validated as a plain token before it is placed in a URL path.
- Only one GitHub App can be connected (`GithubAppAlreadyConnected`); the create form is hidden once connected. Disconnect/replace is deliberately absent: deleting the app would cascade to installations, repositories and (later) pools.
- Manual path: the PEM is normalised (escaped `\n`, CRLF, surrounding whitespace), parsed locally, then proven with `GET /app` including a check that the returned id equals the entered App ID. Nothing is saved on any failure. Both paths audit `github.app_connected` (method, app id, slug; never secrets).
- The default app name is "Jogger" + the first label of the APP_URL host (names are globally unique on GitHub; capped at 34 characters). `setup_url` points back to `/github` so GitHub returns the admin to the page after installing.
- `WebhookReachability` warns (does not block) when APP_URL is not HTTPS or is a local/private address (localhost, loopback, `.test`/`.local`, RFC1918, single-label hosts). It is a heuristic: tunnels and firewalls are invisible to it.
- The Connect page polls every 5 s until an installation exists, then stops; stepper: create → install → repositories (counts come from the `installations`/`repositories` tables, which Tasks 15/16 fill from webhooks). Verified live by inserting an installation while the page was open.
- `<x-ui.table flush>` removes the wrapper border for tables inside cards (a doubled border was spotted in the browser).

## Task 15
**Verified against docs.github.com:** delivery headers (`X-GitHub-Event`, `X-GitHub-Delivery`, `X-Hub-Signature-256`, `X-GitHub-Hook-Installation-Target-ID/-Type`), the HMAC-SHA256 signature scheme (with GitHub's published test vector, used in a test), the requirement to answer 2xx within **10 seconds** (so processing is queued), and that a **redelivery reuses the original `X-GitHub-Delivery` id**.

- **Dedupe vs redelivery.** Because a redelivery has the same id, "seen before" cannot mean "never again": a delivery that is `processed`/`ignored`/`processing` is acknowledged (`200 {"status":"duplicate"}`) and skipped, while one still `received` (job lost) or `failed` is queued once more. Workers *claim* a delivery atomically (`received|failed → processing`), so any number of dispatches runs the handler once; the unique `delivery_id` index settles true races. Verified with 12 identical parallel requests against a real server: 1 accepted, 11 duplicates, 1 row.
- **Route outside the web group** (`routes/webhooks.php`, loaded via `then:`): no session, cookies, CSRF or first-run redirect; authenticated only by the signature. Error bodies use the JSON envelope (`webhooks/*` added to `ApiError`).
- **Verification order:** size cap first (declared `Content-Length` and actual length, 25 MB, before hashing anything) → app lookup from `X-GitHub-Hook-Installation-Target-ID` (a malformed header is refused; if absent and exactly one app is connected, that app is used, the signature must still match its secret) → constant-time HMAC compare → every refusal is the same opaque 401 (logged with ip/delivery id, never the secret).
- **Handler registry** (`WebhookHandlers`, `WebhookHandler` contract): later tasks register `workflow_job` (Task 18), `installation*` (Task 16). Events without a handler are stored and marked **ignored** ("No handler" in the UI): true today for `workflow_job` until Task 18. Handlers must be idempotent (replays and redeliveries re-run them).
- **Statuses:** `received → processing → processed | failed | ignored`. `processing` was added to the Task 12 table's check constraint, and `replayed_at`/`replay_count` columns were added by editing that migration (pre-release; run `migrate:fresh`). Failures keep the error text, rethrow for queue retries (3 tries, backoff 10/60/300 s), and the job's `failed()` hook guarantees nothing is stuck in `processing`. A GitHub rate limit is not a failure: the delivery returns to `received` and the job is released for the stated delay.
- **Replay** (admin only, audited as `github.delivery_replayed`) resets the row, counts replays, queues it, and the list shows a "Replayed ×N" badge; replaying a delivery that is being processed is refused. The list query leaves out the `payload` column (selects `repository` from the JSON in SQL), the viewer truncates at 200 KB, and the page polls every 5 s.
- **Retention:** `jogger:prune` deletes deliveries older than `JOGGER_DELIVERY_RETENTION_DAYS` (default 14, minimum 1) and is scheduled daily at 03:15; later tasks extend it with logs/jobs.
- **Deployment catch for Task 57:** PHP's `post_max_size` (8M by default) is enforced by Laravel's `ValidatePostSize` *before* our middleware, so a real webhook between 8 and 25 MB would be refused with 413 (seen live with a 26 MB body: the message came from Laravel, not our check). The production image must set `post_max_size = 26M` (and the reverse proxy's body limit likewise) to honour GitHub's 25 MB maximum. Typical payloads are a few KB.
- Not exercised against real GitHub: signatures were generated by us (openssl) and by the documented test vector, never by GitHub's servers.

## Task 16
**Verified against docs.github.com** (webhook events page): `installation` carries `installation`, optional `repositories`, `requester`; `installation_repositories` carries `repositories_added`, `repositories_removed` (both required), the top-level `repository_selection` and `installation`; `repository` carries `repository` (and `installation` for app deliveries). **Not verifiable:** the rendered page shows only one action variant per event, so the full action lists (`suspend`, `unsuspend`, `new_permissions_accepted`, `renamed`, `transferred`, `privatized`, …) come from my knowledge of GitHub's API. The handlers are therefore built to survive that uncertainty: lifecycle state is derived from *payload fields*, not only from the action name — `deleted` action, otherwise whether `installation.suspended_at` is set — and unknown repository actions refresh name/visibility from the payload. The fixtures for these events are hand-written to the documented schema.

- **Handlers** (`installation`, `installation_repositories`, `repository`) are idempotent upserts/"set to" operations (`INSERT … ON CONFLICT` via `UpsertInstallation`/`UpsertRepositories`, chunks of 500; 2,500 repositories in one run is tested). A replay or a duplicate delivery leaves identical state (data-provider test over every event).
- **Event order does not matter:** `installation_repositories` or `repository` events for an unknown installation create it from the payload (without touching the status, which only `installation` events know); a later `created` neither duplicates nor loses repositories.
- **`created` for "all repositories"** (and **`unsuspend`**) triggers an authoritative `SyncInstallation` through the gateway, because the payload may not list everything / repositories may have changed while suspended. That sync is best-effort: a GitHub/credentials failure is logged (`github.installation_sync_failed`) and the webhook still succeeds with the payload data; a *rate limit* is not swallowed — the delivery goes back to the queue (data already stored, retry converges). For "selected" access the payload list is complete and no API call is made.
- **`deleted`** keeps the installation row (status `deleted`, history) but removes its repositories (a re-installation gets a new installation id). A `deleted` for an unknown installation is a no-op.
- **`repository.created`** only adds a repository when the installation covers all repositories and is active; for "selected" access GitHub sends `installation_repositories` instead. `renamed/transferred/privatized/publicized/edited/archived` only update repositories we already know. A repository id that appears under a different installation moves to it (GitHub's repo id is the identity).
- **`SyncInstallation`** diffs GitHub's list against ours (added / updated / removed counts), keeps other installations untouched, stamps `installations.synced_at` (column added to the Task 12 migration: run `migrate:fresh`), refuses suspended/deleted installations without calling GitHub, and changes nothing if GitHub fails. Note: an authoritative *empty* list from GitHub empties the installation by design.
- **Known gap:** suspend/unsuspend events racing out of order could leave the wrong status until the next event; the reconciler (Task 37) is the planned backstop. Resync refreshes repositories, not the installation's status/selection (that would need `GET /app/installations/{id}` with the app JWT, not in the gateway yet).
- **Bug found while testing live:** a corrupt stored private key escaped as a raw `DomainException` (500 on Resync, cryptic webhook failure). `AppJwt` now throws a clear `GithubApiError` ("cannot sign requests with the stored GitHub App private key") without echoing key material.
- **UI:** `/github/installations` lists installations (status badge, counts), repositories (filter, 50 per page, public/private badge with a warning when public repositories exist: self-hosted runners should not run untrusted PR code), "last synced", a Resync button (disabled unless active; rate limit → warning toast, API error → error toast, success → "Synced N repositories: …"). It polls every 10 s; verified live with real signed webhooks and a real worker (created → added → renamed → removed → suspended → unsuspended all reflected without a reload).

## Task 17
**Verified against docs.github.com:** the GitHub App web flow uses `https://github.com/login/oauth/authorize` and `…/access_token` (the same URLs Laravel Socialite's GitHub provider uses); `redirect_uri` must match a registered callback URL **and cannot carry extra parameters** (so link intent travels in the session, never in the URL); `state` and **PKCE** (`code_challenge`, S256) are "strongly recommended"; the `scope` parameter is optional and **"No other scopes are supported for GitHub Apps … this parameter does not control permissions"**; `GET /user/emails` works with a GitHub App user token **only if the app has the "Email addresses" user permission (read)**, and returns `verified`/`primary` per address.

**The email permission gap (decision).** Task 14's manifest does not request that permission, and I could not verify the manifest *key* for it (the docs list display names only; I believe it is `email_addresses` or `emails`, and a wrong key could make GitHub reject the whole one-click registration). So the manifest is **unchanged**, and everything that needs email fails **closed**: if GitHub refuses `/user/emails`, the identity is marked `emailsReadable = false` and the person gets an explicit message naming the missing permission; the Connect page shows a hint telling the admin to enable "Email addresses (read-only)" in the app's settings (Permissions & events). What still works without it: signing in with an **already linked** GitHub id, and **linking from Settings → Security** while signed in. What needs it: first-time matching by verified email, and invitation-by-email sign-in. Confirm the key and add it to the manifest during the real run (Task 40).

**Rules (all in `ResolveGithubSignIn`):** (1) a linked `github_id` signs in (deactivated → refused); (2) otherwise the identity's *verified* emails are matched case-insensitively against local accounts, and a match is **never** signed in directly: it must confirm that account's **password** (rate-limited to 5 attempts/minute per account+IP, expires after 10 minutes), only then is the id linked; unverified or unreadable emails never match; an account already linked to a different GitHub id, or without a password, is refused; (3) otherwise a pending, unexpired **invitation** for a verified email creates the account (GitHub-only: no password, name from GitHub, team/admin from the invitation, audited as `user.joined` with `method: github`); (4) otherwise refused: there is no open sign-up. Jogger **two-factor still applies** to a GitHub sign-in (it replaces the password, not the second factor).
- **Emails are read by us**, with the user's token, only counting `verified: true`; neither the profile's public email nor Socialite's own pick is trusted. The access token is used once and never stored (the identity object has no token field; a test asserts it). Any OAuth failure (bad state, bad code, GitHub down) collapses into one friendly message with no internals.
- **Linking/unlinking** from Security both require the current password; linking sets a single-use, 10-minute session flag bound to the user and the callback consumes it. Unlinking is disabled (UI and server) for accounts without a password so nobody locks themselves out; the hint points to "Forgot password". Audited: `auth.github_linked` (method `settings`/`email-match`) and `auth.github_unlinked`. A GitHub account can be linked to only one Jogger user.
- `users.github_login` (display only; refreshed on each sign-in because people rename themselves) was added to the Task 7 users migration: run `migrate:fresh`. `AcceptInvitation` now takes a nullable password plus an optional GitHub identity (an account needs at least one).
- The redirect URI is built from **APP_URL**, not the request host, to match the registered callback exactly.
- **Not exercised against real GitHub:** only the redirect request the real Socialite provider produces was inspected (client id, `redirect_uri`, state, PKCE S256). It also sends an *empty* `scope=` parameter (Socialite always includes the key); the docs call `scope` optional and non-permission-controlling for GitHub Apps, so it should be inert, but this is unconfirmed until a real round trip (Task 40). Controller flows were tested with a fake identity provider, the OAuth wrapper with a mocked Socialite provider and a faked emails endpoint.

## Task 18
- **Status model.** `JobStatus` is `waiting → queued → in_progress → completed` ranked; a webhook only applies if it is strictly ahead of the stored status (`UPDATE … WHERE status IN (earlier statuses)` in `TransitionJob`), so late, duplicate or out-of-order deliveries are silent no-ops. GitHub statuses we do not model (`pending`, `requested`) are treated as `queued`; unknown conclusions become `neutral`. Stale events may still refresh descriptive fields (name, labels, branch) and backfill a missing `started_at`, but never change status or broadcast.
- **First sight of a job in any status creates the row in that status** (e.g. `completed` seen first stays `completed`; it gets `started_at` = GitHub's value, else `completed_at`, best effort). Creation broadcasts once, handled race-safely via the unique `github_job_id`.
- **Unknown repository** (not synced under an installation): job ignored, logged at info; the delivery is still marked processed.
- **`pool_id` / `runner_id` are plain nullable uuid columns** for now; their foreign keys arrive with the pools/runners tables (Tasks 29/31). Status/conclusion also have CHECK constraints in Postgres.
- **Broadcast channels.** Each change is sent on `jobs`, `repository.{id}` and `job.{id}`. Because the payload names jobs, `jobs` is authorized for **global admins only**; `repository.*` and `job.*` follow the `visibleTo` chain (team grants on the installation). Non-UUID ids are refused (403) instead of reaching Postgres (was a 500). Added `Repository::visibleTo` and `WorkflowJob::visibleTo`.
- Tests fake the queue (`Queue::fake()`): broadcasts are queued and no Reverb server runs in the suite. Live broadcast delivery was not exercised in this task.
- `HandleWorkflowJob` only mirrors; the scheduler (Task 34) will react to `JobStatusChanged`/the rows.

## Task 19
- **Enrollment token** `jhe_…`: 1 h TTL, single use, only its sha256 is stored. Claimed with a conditional `UPDATE … WHERE used_at IS NULL AND expires_at > now()` inside the same transaction that creates the host, so a race has one winner and a failed enrollment does not burn the token. Any bad token (unknown, used, expired) gives the same 401 `INVALID_ENROLLMENT_TOKEN`.
- **Agent credential** `jha_…`, shown once in the enroll response, only sha256 stored (`hosts.credential_hash`, unique). `AuthenticateAgent` looks it up on every request and refuses revoked or soft-deleted hosts (401 `INVALID_AGENT_CREDENTIAL`), so revocation is immediate. Added `GET /agent/v1/me` so agents can verify their credential.
- New hosts start `pending`; the first heartbeat (Task 20) makes them `online`. Host names are unique including soft-deleted rows; a clash gets a random suffix instead of failing the enrollment. A name set on the token wins over the agent's hostname.
- Extra columns beyond IMPLEMENTATION §4.1: `hosts.revoked_at`, `enrollment_tokens.used_by_host_id` / `created_at` / `updated_at`.
- Routes live in `routes/agent.php` (no session/CSRF), registered like webhooks. Enroll is throttled to 20/min per IP. Reverb info in the response comes from `broadcasting.connections.reverb`, i.e. REVERB_HOST/PORT/SCHEME: those must be reachable from the agent's machine, not just the browser.
- Not built here: the admin UI/HTTP endpoint that creates tokens (Task 20), `host_commands` (later), WS channel auth for `host.{id}` (still deny-all).

## Task 20
- **Heartbeat** (`POST /agent/v1/heartbeat`): stores the capacity facts (only whitelisted columns; name/status etc. in the body are ignored) and `last_seen_at`. A `pending` or `offline` host becomes `online`; `cordoned`/`draining` keep the operator's state. `HostStatusChanged` is broadcast only on a real status change, not every 10 s.
- **Offline detection:** `jogger:mark-offline-hosts`, scheduled `everyTenSeconds()` (needs `schedule:work` or the scheduler container), flips `online` hosts silent for more than `JOGGER_HOST_OFFLINE_AFTER` (30 s) to `offline` with a conditional UPDATE (a heartbeat landing meanwhile wins). Deliberately **only `online` hosts** go offline: cordoned/draining hosts would otherwise lose the cordon on their next heartbeat, and they get no new runners anyway. `pending` hosts that never connect stay pending.
- **Realtime:** private channel `hosts`, open to every signed-in user (hosts are shared infrastructure and the page is visible to all). The page listens with `echo-private:hosts,.host.status-changed` and polls every 15 s for capacity numbers. Echo delivery in a real browser was **not** exercised (no Reverb connection driven); the listener is tested by dispatching the event, and polling is the fallback.
- **Add host dialog** (admin only): creates a token (optional host name), shows the `docker run` command with `JOGGER_SERVER` (= `APP_URL`) and `JOGGER_ENROLL_TOKEN`, plus the 4-step stepper, polling every 3 s. **The image name `ghcr.io/jogger/jogger-agent:latest` and the env var names are placeholders** until the agent exists (Tasks 22-26, 57). The plain token lives only in the Livewire component state while the dialog is open; closing with the X button leaves it there until Done/Cancel or a reload.
- Verified in the real app: a seeded host showed gauges/status; creating a token in the dialog, then enrolling and heartbeating with `fetch`, advanced the stepper live to 4 of 4. Demo data removed afterwards.
- Revoke/cordon/drain controls are not part of this task.

## Task 21
- **Outbox:** `host_commands` (pending → sent → acked | failed). `DispatchHostCommand` inserts the row and fires `HostCommandAvailable` (ShouldBroadcast + ShouldDispatchAfterCommit): the push is only a hint on `private-host.{id}`, so it is never sent for a rolled-back insert. `AgentHostDriver` implements `HostDriver`, bound in the container. **`HostDriver` currently has a single generic `send(host, type, payload)`**: the `startRunner`/`stopRunner` helpers from IMPLEMENTATION §2.4 need `Runner`/`RunnerSpec` and arrive with Task 31.
- **`GET /agent/v1/commands`:** due commands (`available_at <= now`) of that host only, oldest first (max 50), picked with `FOR UPDATE SKIP LOCKED`, marked `sent`, `attempts + 1`, `sent_at` set. A sent command is not returned again until redelivery.
- **`POST /agent/v1/commands/{id}/ack`** `{ok: bool, result?: object}`: `ok` → `acked`, otherwise `failed` (the result is stored either way). Only pending/sent commands change, so a repeated or late ack is a harmless 200 showing the final status. Commands of other hosts and malformed ids are 404, indistinguishable from missing.
- **`jogger:redeliver-commands`** (scheduled every 15 s): a command `sent` for more than 30 s goes back to `pending` and is re-hinted; after the 5th delivery without an ack it becomes `failed` with `{"error": "not acknowledged after 5 attempts"}`. Conditional updates, so an ack landing meanwhile wins. Because delivery is at-least-once, **agents must execute commands idempotently** (by command id).
- **Agent channel auth:** `POST /agent/v1/broadcasting/auth` signs only `private-host.{own id}` (else 403) using the broadcaster's own signature. Browsers still cannot join `host.*` (user auth stays deny-all). Not exercised against a real Reverb server/Pusher client yet (the agent's pusher client arrives with Task 23/24).
- **Bug found by the tests:** `available_at` defaulted to the database clock while "due" is compared with the app clock; under test time travel (and with any real clock skew) commands looked not yet due. It is now set from the app clock on insert.
- No per-host rate limit on the agent API yet (enroll is limited by IP); worth revisiting in Task 56 hardening.

## CI activity heatmap (user request, between Tasks 21 and 22)
- New reusable `<x-ui.activity-heatmap :days :failed :weeks unit>` (GitHub-style calendar: weeks as columns, Monday first, month labels, Less→More legend, `title` tooltip per day including failed count; five shades of the `info` token, so it follows light/dark mode) and `GetJobActivity` (jobs **queued** per day, last 26 weeks, restricted by `visibleTo`; days are UTC dates). Shown on the Overview in a "CI activity" card with the total, and in the UI kit page.
- Counts jobs by `queued_at`, so it measures CI load, not run duration. Task 45 (Overview dashboard) should keep this card when it replaces the placeholder.
- Verified visually in the real app with ~580 generated jobs (removed afterwards).

## Task 22
- **Standard library only.** The Go agent has no third-party dependencies yet (module `github.com/PatrickePatate/jogger/agent`, Go 1.25+; the dev box has 1.26). The config file is therefore a **flat TOML subset** (`key = "value"`, `#` comments; tables/arrays are rejected with a line number, not ignored). Docker's version is read with a tiny HTTP-over-unix-socket call to `/version` rather than the Docker SDK; the SDK arrives with Task 24 when containers are managed. The Pusher client (Task 23) is the one piece that may justify `github.com/coder/websocket`.
- **Precedence:** flags > environment (`JOGGER_SERVER`, `JOGGER_ENROLL_TOKEN`, `JOGGER_DATA_DIR`, `JOGGER_HOSTNAME`, `JOGGER_DOCKER_SOCKET`, `JOGGER_HEARTBEAT_INTERVAL`, `JOGGER_CONFIG`) > config file (default `/etc/jogger-agent/config.toml`; a missing *default* file is fine, a missing *explicit* one is an error). These env names are what the Add-host dialog's `docker run` command already uses.
- **Enrollment** stores `credential.json` (0600, atomic temp-file + rename, inside a 0700 data dir) holding host id, credential and Reverb info. **A stored credential always wins over a token**, so a container restarted with the same (already used) `JOGGER_ENROLL_TOKEN` keeps working. A credential issued by a different server URL is refused with a message naming the file to delete. If the host enrolls but the file cannot be written, the error says the credential is lost and the host must be revoked (the server never shows it twice).
- **Retries:** network errors, 5xx and 429 are retried; 4xx rejections (bad token) and a revoked credential (`INVALID_AGENT_CREDENTIAL`) stop the agent with a clear error (exit 1). Backoff is 1 s, 2 s, 4 s … capped at 60 s with up to +25 % jitter. In the heartbeat loop a failed report is retried after the backoff delay, capped at 6 × the interval, instead of waiting a whole interval; the counter resets on success.
- **Heartbeat facts:** CPU "used" is cores busy, from two `/proc/stat` samples (so the first heartbeat omits it); memory used excludes reclaimable cache (`MemAvailable`); disk free is measured on the data directory's filesystem (a host with a separate Docker data disk should be measured on that: revisit in Task 24/26 cleanup); architecture is reported as `x64`/`arm64`. Probes that fail leave their field empty instead of failing the heartbeat. Linux only (reads /proc, `Statfs`).
- **Verified live** against the real server: enrolled with a token (credential file mode 0600), host went `online` with real CPU/memory/disk/Docker 29.9.2 figures, restart without a token worked, revoking the host made the agent exit with the revoked message, and with the server down the agent logged retries at growing intervals. Binary: 6 MB with `-ldflags "-s -w"` (limit 20 MB).
- CI gets an `agent` job (vet, `go test -race`, build + size check). `agent/bin/` is git-ignored. No Docker image yet (Task 57).
- A leftover empty-ish temp data directory from the live check exists under `/tmp` (my cleanup of it was blocked by a safety check); it is harmless.

## Task 23 — Agent: Reverb client and command pipeline

- **The agent depends on `github.com/coder/websocket`** (its first third-party module). A hand-rolled RFC 6455 client was not worth the risk; the library is small, maintained and context-aware.
- **The socket is a hint, polling is the delivery.** `Pusher.Run` reconnects forever with the shared backoff and never fails the agent; every (re)subscription also triggers a fetch so nothing sent while disconnected waits for the 15 s poll. A silent socket for `2 × activity_timeout + 10 s` is treated as dead.
- **Execution is once per command id, in memory.** The dispatcher remembers the last 2048 ids. A redelivery of a running command is ignored; a redelivery of a finished one repeats the cached ack (that is how a lost ack heals) without re-running the handler. After an agent restart the memory is gone, so handlers must still be idempotent (Task 24 keys on the runner id).
- **Failure shapes:** unknown type → `ok:false, result:{error:"unsupported", type}`; handler error or panic → `ok:false, result:{error}`. Both are final acks, so the server does not redeliver them forever.
- `main.go` starts the dispatcher with no handlers yet; Task 24 registers `runner.start` / `runner.stop`. Until then every command is acked `unsupported`.
- Not exercised: a real Reverb server (tests use a fake Pusher endpoint). Worth a manual check once Task 24 gives the agent something to do.

## Task 24 — Agent: runner container lifecycle and hardening

- **Docker Engine API over the unix socket, stdlib only** (`agent/internal/docker`), not the Docker SDK: the agent needs about ten endpoints and the SDK would be a large dependency tree in a security-sensitive binary.
- **Idempotency is by label, not by memory.** `runner.start` looks up a container labelled `jogger.runner=<id>` first; if there is one it creates nothing and reports `existing:true`. This survives agent restarts, unlike the dispatcher's in-memory de-dupe from Task 23. `container_started` is only emitted for a container this call really created. A failed start removes its half-built network/container so a retry starts clean.
- **Hardening as in IMPL §10.3:** `no-new-privileges`, `CapDrop ALL`, `PidsLimit 2048`, a private bridge network per runner, no privileged mode, no host socket. `MemorySwap` is set equal to `Memory` so hitting the limit OOM-kills instead of swapping the host.
- **`docker_mode` other than `none` is rejected** with an error ack until the DinD sidecar exists (Task 26).
- **Exit reporting uses the engine event stream** (`die`, `oom` filtered by `jogger.host`). `AutoRemove` deletes the container, so the exit code and OOM flag can only come from events; the watcher also removes the runner's network. It resumes from the last event time after a reconnect, so a duplicate `container_exited` is possible across a blip; the control plane must treat it idempotently (Task 27).
- **Events are best-effort.** `POST /agent/v1/events` does not exist until Task 27; the emitter retries transient failures (8 attempts, backoff), drops rejections (404 etc.) with a warning, and keeps a bounded queue (oldest dropped).
- `Spec.Command` (payload `command`) overrides the image's arguments; it exists so tests can use an alpine stand-in and is trusted because it comes from the control plane.
- **Test environment note:** the integration tests use `alpine:latest` and skip themselves when it is not local. On this dev machine the CLI uses Docker Desktop's socket, not `/var/run/docker.sock`, so run them with `DOCKER_SOCKET=$HOME/.docker/desktop/docker.sock go test ./internal/docker`. CI pulls alpine first. Testcontainers was not used: the plain API client already is the thing under test.
- Not verified: a real GitHub runner image, and `stats.go` only provides container listing/counting for now (resource figures in the heartbeat are a later task).

## Task 25 — Agent: log shipper

- **Wire format (for Task 27 to implement):** `POST /agent/v1/runners/{runner_id}/logs` with `{"seq": int, "data": string, "eof"?: true}`. `seq` starts at 1 per runner and is gapless; a retry resends the same batch with the same `seq`, so the server must treat a repeated `seq` as success. The last batch has `eof:true` and empty data.
- **Batches** are cut at 64 KB or every 250 ms, preferably on a line break and never inside a UTF-8 character. Non-UTF-8 bytes would still be replaced by JSON encoding.
- **`seq` is assigned when a batch is first offered to the server, not when it is cut.** That is what makes dropping safe: batches the server never saw can be discarded under memory pressure without leaving a gap, while the head batch (possibly received, ack lost) is never dropped.
- **Bounded memory:** `MaxBuffer` (8 MB default). Over the cap, the oldest never-offered batches are dropped and the next batch starts with `[jogger: N bytes of output dropped while the server was unreachable]`.
- **A rejected batch (4xx other than 429/408) is discarded**, not retried forever; this is also what happens until Task 27 adds the endpoint. Network errors and 5xx/429 retry with backoff.
- **Source:** the engine log stream (`follow`, stdout+stderr interleaved, demultiplexed from the 8-byte frame headers). The follower starts for each newly created runner container and sends the `eof` batch when the stream ends (container exit). A broken stream resumes with `since = now − 1 s`, so a second of repeated lines is possible but gaps are not.
- **Known limit:** shipper state (`seq`, buffer) is in memory. If the agent restarts while a runner is alive, that runner's remaining output is not shipped (no follower is started for an `existing` container, because restarting `seq` at 1 would collide with what the server already has). Persisting `seq` is a follow-up if this matters in practice.
- Docker integration tests include a real container's stdout and stderr both arriving through the stream.

## Task 26 — Agent: housekeeping, preflight, DinD sidecar, packaging

- **DinD (`docker_mode: "dind"`)**: per runner, a private network, a labelled certificate volume and a `docker:dind-rootless` sidecar (alias `docker`, TLS via `DOCKER_TLS_CERTDIR`). The runner gets `DOCKER_HOST=tcp://docker:2376`, `DOCKER_TLS_VERIFY=1` and the client certs read-only. The host socket is mounted nowhere (asserted on both containers). Image overridable per command (`dind_image`).
- **The sidecar is `Privileged`.** Rootless dind still needs it (user namespaces and mounts inside the container). It is the only privileged container the agent creates, holds no secrets, shares its network with one runner only, and is removed with it. The runner itself keeps `CapDrop ALL` / `no-new-privileges`. Resource limits apply to both, so a job's total is up to 2× the configured figure; revisit if that surprises operators.
- **Sidecars carry `jogger.sidecar=<runner>` (not `jogger.runner`)** so lookups by runner never match them and the event watcher ignores their exits. All teardown (sidecar, volume, network) goes through one idempotent `teardown`, used by stop, failed start, runner exit and orphan removal.
- **Preflight** (`dind_supported` in enrollment and heartbeat) checks `max_user_namespaces`, the Debian `unprivileged_userns_clone` sysctl and Ubuntu 24.04's `apparmor_restrict_unprivileged_userns` (which silently breaks rootless Docker). The **server does not persist or show it yet**: `HeartbeatController` validates a fixed field list and ignores the rest. Storing it and disabling the DinD option in the UI belongs with the pool/host UI work.
- **Orphans** (agent-side, every 5 min): Jogger-labelled containers older than 10 min whose runner id is not in `GET /agent/v1/runners` are removed with their network/volume. **That endpoint does not exist yet** (Task 29-31); until it does, any error means "skip", never "everything is an orphan". The server must return the complete list of this host's non-terminal runners, because an incomplete list will cause live runners to be killed after 10 minutes.
- **`host.prune`** removes dangling images and only this host's labelled volumes; it never prunes unlabelled volumes or tagged images on a shared machine.
- **Update check** against `GET /agent/v1/version` (`{latest, url?, sha256?}`), reported as `update_available` in the heartbeat (also not yet persisted server-side). `--auto-update` / `JOGGER_AUTO_UPDATE` is off by default; when on, it downloads, **requires a matching SHA-256**, atomically replaces the binary and exits so the supervisor restarts it. A checksum from the same server as the binary only protects against corruption, not a compromised server; signing releases is a Task 57 question. A pre-release (`-dev`) is older than its release; unparsable versions are never "newer".
- **Packaging**: `docker/agent/Dockerfile` (golang build → `distroless/static:nonroot`) gives an **8.5 MB** image that runs; `make build-all` produces linux amd64/arm64. The Dockerfile runs as `nonroot`, so the operator must give it Docker socket access (group add) — the compose/install docs in Task 57 must say so.
- **CI**: added golangci-lint (v1.64, `agent/.golangci.yml`: errcheck ignored in tests and for `CloseNow`), the cross-compile step, and the existing alpine pull. Verified lint locally with the same version.
- Tested for real against Docker: orphan removal (known and young runners untouched), prune, and a DinD runner + sidecar start/stop with the `docker:dind-rootless` image pulled locally. Not tested: a job actually running `docker build` through the sidecar, and the self-update path against a real supervisor.

## Task 27 — Control plane: event and log ingestion

- **`job_logs` is now keyed by `runner_id`** (new migration; `job_id` nullable + unique, `host_id`, `last_chunk_at` added). Logs arrive per container, often before the `in_progress` webhook says which job the runner took; `job_id` is filled lazily from `workflow_jobs.runner_id`. Chunks received before the job is known are stored but not broadcast.
- **Until runners exist (Task 31) ownership is "first host to write owns the log"**; another host gets 404. Task 31 should check the runner's `host_id` instead.
- **Gaps are accepted, not rejected** (deviation from the acceptance wording). The agent sends in order from one goroutine and *discards* a refused batch, so rejecting `seq > last+1` would lose the rest of the log. A jump writes `[jogger: N log batch(es) missing]`. `seq <= last_seq` and anything after finalize answers 200 `accepted:false`.
- **Storage**: plain append to `live/<runner>.log` on the `logs` disk (new, `storage/app/logs`, `JOGGER_LOG_PATH`); `eof` gzips to `archive/Y/m/<runner>.log.gz` and sets `expires_at = now + log_retention_days`. The disk **must be local** (in-place append). `jogger.log_disk` default changed from `local` to `logs`.
- **`jogger:flush-logs`** (every 5 min) closes logs silent for 15 min without `eof` (agent died) and deletes expired logs. No Redis buffering: appends are direct, so there is nothing else to flush.
- **Masking**: `SecretMasker` replaces values (≥ 4 chars, each line of multi-line secrets, and their base64) with `***` before storage and broadcast. Values come from `SecretSource`, bound to `NoSecrets` until secrets exist. A secret split across two batches is not caught.
- **Truncation** at `log_max_bytes_per_job`: cut on a UTF-8 boundary, marker line appended, `truncated=true`; later batches are acknowledged and discarded.
- **Broadcast** `log.chunk` on `private-job.{id}` in slices of ≤ 2000 characters (Pusher message cap).
- **The log text is read from the raw request body**: the global `TrimStrings` middleware strips the trailing newline otherwise.
- **Events**: `POST /agent/v1/events` → `ApplyAgentEvent` → `RunnerEvents` contract, bound to a logging stub until Task 31. Unknown types return 202 `handled:false`.

## Task 28 — Host operations: cordon, drain, revoke, local host

- **Operator actions** are conditional updates through `SetHostStatus` (broadcast once, audit logged). Cordon ↔ Resume; Resume restores `online`/`offline`/`pending` from `last_seen_at`. Revoked is terminal.
- **Drain settles on heartbeat**: `DrainHost::settle` turns `draining` into `cordoned` when `RunnerCensus::activeOn($host)` is 0. The census is bound to `NoRunners` (always 0) until Task 31, so today a drain cordons immediately. A draining host that is offline settles only when it heartbeats again; Task 31 should also call `settle` when a runner reaches a terminal state.
- **Revoke** nulls the credential hash, sets `revoked_at`, fails undelivered commands (`host_revoked`). "Disconnect its channel" is met by denial, not a kick: every agent HTTP call and channel authorization looks the host up, so both fail at once; an already-open socket stays connected until it drops but only carries hints the agent can no longer act on. Revoked hosts are hidden from the Hosts page.
- **Local host**: `JOGGER_LOCAL_HOST=true` plus a shared `JOGGER_LOCAL_HOST_TOKEN` (`jhe_` + ≥ 32 chars) in `.env`. `jogger:install` (migrate + this) registers that value as a one-time enrollment token named `local` (24 h window, re-run extends it); the compose `agent` service (profile `local-host`) passes it as `JOGGER_ENROLL_TOKEN`. Off by default.
- **`docker/compose/docker-compose.yml` is an initial draft**: image names are placeholders, the app command falls back to `artisan serve`, and nothing has been run from it. The packaging tasks own making it real. The agent container needs `DOCKER_GID` to match the socket's group.

## Task 29 — Runner pools: model and management UI

- **One rule set (`PoolRules`) for the form and the API**, keyed by column names. `CreatePool`/`UpdatePool` validate themselves, so no caller can skip the rules. Where a pool registers (installation, scope, target) is immutable after creation.
- **Visibility and roles**: a pool is visible through a direct team grant (grant type `pool`) *or* a grant on its installation; the effective role is the higher of the two. Viewers see, operators create/edit/pause. Creating requires operator on the installation. The Teams admin page does not offer pools as grantables yet.
- **Public repository** (repository scope, `private=false`): creation fails with a `confirm_public` error unless `confirm_public=true`; the form shows a blocking warning with a checkbox. Organization-scope pools are not checked for public repositories inside the organization.
- **Organization scope must target the installation's own account**; repository scope must be a repository synced for that installation.
- **Name is unique per installation among non-deleted pools** (partial unique index) and checked in validation.
- **Image regex** accepts `registry[:port]/path[:tag][@sha256:…]`; it is a syntax check only.
- **API**: `GET /api/v1/pools`, `GET /api/v1/pools/{id}`, `POST`, `PATCH` (operator token + operator role on the pool). `status` is patchable (`active`/`paused`). No DELETE yet.
- Pool cards show the `<x-pool-meter>` with zero counts until runners exist (Task 31).
- `workflow_jobs.pool_id` now has its foreign key (`ON DELETE SET NULL`).

## Task 31 — Runner model, state machine, transitions

- **`RunnerState`** matches IMPL §2.2 exactly (a data-provider test checks all 36 pairs against a table written out separately). `live()` = provisioning/idle/busy (counts against `max_runners`); `present()` adds terminating (may still have a container).
- **`TransitionRunner`** is the only writer of `state`: `UPDATE … WHERE state IN (previous)`; 0 rows = stale, silent. Sets `state_changed_at`, `started_at` (first idle/busy), `finished_at` (terminal), broadcasts `runner.state-changed` on `private-runners` (admins) and `private-pool.{id}` (pool visibility), and settles a draining host.
- **Agent events** (`ApplyContainerEvent`, replaces the Task 27 stub): `container_started` → idle. `container_exited`: OOM → failed `oom`; while terminating → gone (keeps the reason given at terminate); exit 0 → terminating → gone `completed` (the table has no direct busy → gone); otherwise failed `container_exited`. `exit_code` stored. A runner that never took a job is deregistered from GitHub. Events for a runner on another host are ignored.
- **`TerminateRunner`**: → terminating with a reason, `runner.stop` to the host, GitHub `deleteRunner` inline and best effort (errors are logged; the reconciler of Task 37 sweeps leftovers). A runner with no host goes straight to gone. It stays `terminating` until the agent reports the exit; the reaper (Task 35) handles hosts that never answer.
- **`GET /agent/v1/runners`** now exists and returns every present runner of the host (including provisioning ones), which switches on the agent's orphan cleanup.
- `RunnerCensus` is now a real count; `job_logs` writes are refused when the runner is placed on a different host; the pool cards show live counts and refresh on runner events.
- Circular dependency avoided: `DrainHost` → `RunnerCensus` must not resolve to a class that depends on `TransitionRunner`.

## Task 32 — Pool matching and host placement

- **`MatchPool(repository, labels)`**: candidates are pools of the repository's installation that target that repository or its owner (organization scope). A pool matches when its labels contain all the job's labels, **case-insensitively**. Ranking: fewest labels (most specific), then lowest `live / max_runners`, then name. Returns a `PoolMatch` with the pool or a code (`NO_MATCHING_POOL`, `POOL_PAUSED`) and a ≤ 200-character reason for `unmatched_reason`.
- **A job is only reported as unmatched when it asks for `self-hosted`** (`MatchPool::wantsSelfHosted`); a job with custom labels only and no matching pool is ignored like a GitHub-hosted one, since Jogger cannot tell them apart.
- **`PickHost(pool)`**: status `online` only; host labels ⊇ `allowed_host_labels`; if the pool's labels name an architecture (`x64`, `arm64`, `arm`) the host's must be one of them; free CPU/memory ≥ the pool's limits (defaults 1 core / 1024 MB when unlimited). Free = total − max(reported usage, reservations of present runners placed there). Picks the host with the most free memory.

## Task 33 — Provision runner (reserve, JIT, command)

- **`ProvisionRunner(pool)`**: reserve (transaction, `FOR UPDATE` on the pool row, live count < `max_runners`, `PickHost`, insert `provisioning`) → GitHub JIT config (outside the transaction) → `runner.start` through the outbox. Returns null when paused or full, without touching GitHub; throws `HostUnavailable` when no host fits. Any failure after the reserve marks the runner `failed` (`jit_failed` / `dispatch_failed`), which frees the slot.
- **`runner.start` payload**: `runner_id, name, image, jit_config, cpu, memory_mb, env, labels, docker_mode` (the agent's `Spec`). The pool's labels are sent to GitHub as the runner's full label set.
- **The JIT config sits in `host_commands.payload` in clear until the agent acknowledges the command; the ack removes it.** It is single-use and short-lived, but a database dump taken in that window contains it.
- **`ProvisionRunnerJob(poolId, jobId?)`**: waiting conditions release the job back to the queue and record a reason on the workflow job (`NO_CAPACITY` 30 s / 20 s, `GITHUB_RATE_LIMITED` for `Retry-After`); real errors retry with backoff up to 3 times; it stops when the workflow job is no longer queued; gives up after 6 h.
- The host-capacity reservation happens inside the pool lock but two *different* pools provisioning at the same instant can both pick the same nearly-full host. The limits are per container, so the effect is over-subscription, not failure.
- `ResolvesSecrets::envFor(pool)` is bound to an empty implementation until Phase 8.

## Task 30 — Default runner image

- `docker/runner/Dockerfile`: Ubuntu 24.04, `actions/runner` pinned by `ARG RUNNER_VERSION` (default 2.328.0), Docker CLI + buildx + compose from Docker's apt repository, user `runner` (uid 1001), `tini` as init. No language toolchains.
- The entrypoint exits 64 with a clear message without `JIT_CONFIG` and unsets it before `exec ./run.sh --jitconfig`.
- Built and checked locally for **amd64 only** (1.28 GB, `id -u` = 1001, exit 64 without config). **arm64 was not built**; the workflow builds both with QEMU. The workflow passes `actionlint` but has never run, and nothing was pushed to a registry.
- The weekly workflow resolves the latest runner release itself, because GitHub refuses outdated runners; the Dockerfile default will go stale and only matters for local builds.
- The image name in `config/jogger.php` (`ghcr.io/jogger/jogger-runner:latest`) is still a placeholder; the workflow pushes to `ghcr.io/<repository owner>/jogger-runner`.
- A real job has not been run in this image yet (Task 40).

## Task 34 — Webhooks wired to scheduling and teardown

- `HandleWorkflowJob` mirrors the job, then acts on the job's resulting status (not the webhook's `action`, so late events cannot schedule a job that already moved on): queued → `ScheduleJob`; in progress → `AssignJobToRunner`; completed → `TerminateRunner(…, 'completed')`.
- **Runners are not reserved for jobs.** GitHub gives a job to any matching runner, so `ScheduleJob` only compares counts: it provisions when the pool's waiting runners (provisioning + idle) do not exceed its *other* queued jobs. A redelivered `queued` event therefore starts nothing.
- **Unmatched**: a `self-hosted` job without a pool gets `unmatched_reason`; a GitHub-hosted job (`ubuntu-latest`) gets neither pool nor reason.
- **`completed` without `in_progress`** links the runner from the completed event's `runner_name` first, then terminates it.
- **Cancelled before pickup**: `TrimIdleRunnersJob` runs 30 s later and terminates idle runners beyond `queued jobs + min_idle` (newest first, reason `job_cancelled`). A runner still `provisioning` at that moment is left to the idle timeout of Task 35.
- The runner link is by name (`jogger-…`), falling back to GitHub runner id within the job's pool.

## Task 35 — Stuck-runner reaper and timeouts

- `jogger:reap-runners` every 30 s. Rules, each a conditional transition (so a second run does nothing):
  - **provisioning > `runner_provision_timeout` (180 s)** → failed `provision_timeout`, `runner.stop` sent, GitHub runner deleted, then the oldest queued job of that pool is scheduled again (which provisions only if the pool is actually short).
  - **host offline > 5 min, or revoked** → every present runner there → failed `host_lost` (no command is sent to a dead host).
  - **idle > pool `idle_ttl_seconds`** → terminated `idle_timeout`, oldest first, never below `min_idle`.
  - **terminating > 5 min without an exit report** → gone (added: otherwise a lost `container_exited` would hold a host "draining" forever).
- **`PoolDemand::deficit(pool)` = `min_idle` + queued jobs of the pool − runners provisioning or idle.** `ScheduleJob`, `ProvisionRunnerJob` (re-checked when it finally runs), `TrimIdleRunners` and the warm-pool reconcile all use this one number, replacing Task 34's ad hoc count.
- A job whose runner died mid-job (`host_lost`, crash) is not re-queued: GitHub fails such a job itself and a re-run arrives as a new `queued` event.

## Task 36 — Warm pool and scale policy

- `jogger:reconcile-pools` every 15 s: for each active pool, provision `deficit` runners (at most 10 per pool per pass), stopping at `max_runners`, on no host capacity, or on a rate limit. It provisions synchronously inside the command, not through the queue.
- A queued job in a warm pool triggers one provisioning immediately (deficit counts the job), so the pool is back at `min_idle` as soon as the job takes an idle runner; no runner is started "for" the job specifically.
- Scale-down is only the idle timeout; `min_idle` runners are never reaped for idleness, so a warm runner can sit for days. GitHub may expire a long-idle JIT registration; Task 37's reconciler marks such runners failed.
- Concurrency with webhooks is bounded by the pool row lock in `ProvisionRunner`; the invariant test is Task 38.

## Task 37 — Reconciler against GitHub

- `jogger:reconcile` every minute, single-flight through a cache lock (5 min expiry); an overlapping run prints a line and exits 0. Rate limits and GitHub errors end the pass quietly.
- **Jobs**: asks GitHub for queued jobs of (a) repositories targeted by an active repository-scope pool and (b) repositories of organization-scope installations that had a job in the last 24 h; at most 50 repositories per pass. Unknown jobs are imported; every queued job is passed to `ScheduleJob` again (a no-op unless the pool is short). **A brand-new repository in an organization pool whose very first webhook was lost is not discovered** — listing every repository of a large organization each minute is too expensive.
- **Runners**: per distinct pool scope, `jogger-…` runners GitHub lists that Jogger does not know or knows as gone/failed are deleted; idle/busy Jogger runners GitHub has not listed for 5 min become failed `github_missing` and get a `runner.stop`.
- **Two Jogger instances serving the same repository or organization would delete each other's runners** (both use the `jogger-` prefix). One instance per GitHub scope is assumed.

## Task 38 — Rate-limit lane, concurrency invariants, failure injection

- **`GithubLane`** (cache key per installation, so Redis in production): `pause(installation, seconds)` / `pausedFor()`. **`RateLimitedByGithub`** job middleware releases a job while its lane is paused and pauses the lane when the job throws `GithubRateLimited` (until `Retry-After`, default 60 s). `ProvisionRunnerJob` uses it; `jogger:reconcile-pools` and both GitHub reconcilers check and set the same lane, so one 429 quiets all GitHub work for that installation until the reset, then everything resumes on its own.
- **A failed `runner.start` ack now fails the runner immediately** (`start_failed`, GitHub runner deleted) instead of waiting for the 180 s provision timeout.
- **Failure reasons** now in use — runners: `jit_failed`, `dispatch_failed`, `start_failed`, `provision_timeout`, `host_lost`, `github_missing`, `oom`, `container_exited`, plus the normal `completed`, `idle_timeout`, `job_cancelled`, `terminated`; jobs (`unmatched_reason`): `NO_MATCHING_POOL`, `POOL_PAUSED`, `NO_CAPACITY`, `GITHUB_RATE_LIMITED`.
- **Concurrency test** forks 10 processes × 5 provisioning attempts against a pool with `max_runners = 5`, 20 iterations, each child on its own connection: always exactly 5 runners. It needs `pcntl` (skipped otherwise), commits its rows and truncates the runner/pool/host/installation tables before and after, so it must not run in parallel with other tests on the same database.
- **Lost ack**: the server re-offers the same command id after `REDELIVER_AFTER`; "one container" then rests on the agent's idempotency (tested in Go in Tasks 23-24), not re-tested end to end here.

## Task 39 — Image management

- **`host_images`** (host, image, `pending`/`ready`/`failed`, error, command id). `EnsureImageOnHosts(pool)` sends `image.pull {image}` to every online, cordoned or draining host carrying the pool's `allowed_host_labels`, skipping hosts where the image is already ready or on its way. It runs when a pool is created and when its image or host labels change; "Pull again" in the pool form forces it everywhere.
- **Agent**: new `image.pull` handler (calls the existing `Pull`). Agents older than this commit answer `unsupported`, which shows as a failed pull; harmless.
- **Results** come back through the command ack, now handled by `ApplyCommandResult` (also home of the `runner.start` failure rule). Only the ack that settles a command has effects.
- **A failed pull blocks nothing**: it is shown per host with the error, and `PickHost` merely prefers hosts whose image is `ready` (then most free memory). With no warm host it falls back to any fitting host, which pulls when the runner starts.
- **Not done: private registry credentials.** The secrets store does not exist yet (Phase 8), so pulls are anonymous; the payload has room for an auth field later. `ready` also goes stale if someone prunes images on the host (`host.prune` removes only dangling images, so normally it holds).
- A host that enrolls after the pool was saved gets no pre-pull until the pool's image changes or someone clicks "Pull again".

## Task 40 — First real end-to-end run

- **Not performed.** It needs a real GitHub App, repository and public webhook URL, which this work could not create. `docs/E2E.md` holds the procedure, the checks, the parts most likely to need fixing, and an empty results table; `tests/Script/e2e.sh` dispatches the workflow with `gh` and follows the job through `/api/v1/jobs` (added in Task 41). The script is syntax-checked only.
- Consequence: the "End-to-end alpha" milestone is **not reached** until someone runs this. Everything after it is built on the fake gateway.

## Task 41 — Log retrieval, scrollback, retention

- **Scrollback is by byte offset, not by `seq`** (deviation): `GET /jobs/{job}/log?offset=&limit=` returns `{data, offset, next_offset, size, last_seq, finalized, truncated}`; a negative offset means "last N bytes"; windows start on a line boundary; at most 1 MB per request. The log is stored as plain text without a seq→offset index, and the viewer does not need one: it loads scrollback, remembers `last_seq`, and ignores broadcast chunks at or below it. Size and `last_seq` come from the same row read, so the two always agree.
- Works the same for live files and gzip archives (`gzseek`). It is not signed; it is behind the session and the job's visibility (404 for jobs the user cannot see).
- **Download**: `GET /jobs/{job}/log/download` and `GET /api/v1/jobs/{id}/logs` stream plain text; `?format=gz` hands out the stored archive once the log is finalized. Text is already masked at ingestion.
- **Jobs API added here** because the E2E script needs it: `GET /api/v1/jobs` (filters `repository`, `status`, `pool`; cursor pagination, newest first) and `GET /api/v1/jobs/{id}`.
- **`jogger:prune`** now deletes expired logs (file + row), and completed jobs older than `job_retention_days` together with their log files. Jobs that never completed are kept however old. Log expiry moved here from `jogger:flush-logs`, which only closes idle logs now.

## Task 42 — Jobs index (live)

- Filters (repository, workflow contains, status, pool, host, time range) are `#[Url]` properties, so they combine and survive reload/sharing. Status options are outcome-oriented: queued, no matching pool, running, succeeded, failed (incl. timed out), cancelled.
- Cursor pagination, 25 per page, ordered by `id` (UUIDv7 = creation order; uses the primary key index).
- **Live updates**: administrators listen on `private-jobs`; that channel is admin-only, so everyone else gets `wire:poll.5s` instead of a subscription that would be refused. A per-repository subscription for non-admins is a possible refinement.
- The 10k-job benchmark is a test (first page under 500 ms including Livewire rendering).

## Task 43 — Job detail with stepper and live log terminal

- Stepper Queued → Provisioning → Running → Completed with durations; the provisioning step turns failed for "no matching pool" and when the pool's newest runner since the job was queued failed to start. The page listens on `private-job.{id}` and also polls every 5 s while the job is open (runner state and running durations have no job-channel event).
- **Log viewer** (`resources/js/log-terminal.js`, xterm.js + fit + search addons): loads the last 512 KB of stored log, then follows `log.chunk`. Merge rule: stored text ends at `last_seq`; only chunks with a higher `(seq, part)` are written. Live chunks received while a fetch is in flight are held back. After a websocket reconnect it catches up by byte offset. 50 000-line scrollback; "Load earlier lines" rebuilds the terminal from further back (a terminal cannot prepend).
- **Not verified in a browser.** The Livewire side is tested; the JavaScript builds but has not been exercised against a real stream, so ANSI rendering, the merge on reload and the 1-second latency are unproven until the browser tests of Task 58 or a manual run.
- Bundle grew to about 460 KB (120 KB gzipped) because xterm is in the main entry; splitting it into a lazily loaded chunk is an easy follow-up.

## Task 44 — Runners page and runner actions

- Runners page: present runners plus those that ended in the last 24 h (max 200), state filter in the URL, live for administrators (`private-runners`) and polled every 5 s for others. Clicking a name opens a lifecycle stepper under the row (in place of a drawer).
- **Terminate** needs operator on the runner's pool. Idle/provisioning: one confirm. **Busy: a second, explicit "Terminate anyway"** step server-side, not just a browser confirm. Reason `terminated_by_user`, audit action `runner.terminated` with `forced`.
- API: `GET /api/v1/runners` (live by default; `state`, `pool` filters), `GET /api/v1/hosts` (any token; no revoked hosts), `DELETE /api/v1/runners/{id}` (operator token + operator on the pool; 409 for a busy runner without `force=true`).

## Task 45: Overview dashboard

- The aggregates are cached per user for 5 s in the default cache store; a `job.status-changed` or `runner.state-changed` broadcast received by the page drops that user's entry. There is no server-side event listener clearing every user's cache: with a 5 s lifetime it would add writes for no visible gain.
- Hosts and the getting-started checklist are shown to administrators only, as hosts are an admin resource. Once every step is done the result is remembered forever (`overview:checklist-done`), so the checklist costs no queries afterwards.
- "Queue depth" counts `queued` and `waiting` jobs; the 24 h success rate is success / (success + failure + timed out), ignoring cancelled and skipped jobs.

## Task 46: Command palette

- One Livewire component in the app layout. Opening, closing and arrow/Enter navigation are Alpine only; typing calls the server after a 300 ms debounce. Pages are listed from the routes that exist; resource search starts at two characters, five results per group, through the same `visibleTo` scopes as the pages (hosts for administrators only).
- Search is `ILIKE '%term%'` without a trigram index: at 10 000 jobs a sequential scan is a few milliseconds. The 200 ms target was not measured at that volume; add a `pg_trgm` index if job counts grow far beyond it.
- Keyboard behaviour is not covered by a test (no browser tests until Task 58).

## Task 47: Pool and host realtime polish

- Pools page: a callout lists queued jobs whose reason is `NO_MATCHING_POOL`, grouped by label set (label order ignored), up to eight groups, each with "Create pool for these labels", which opens the form with `?labels=` prefilled.
- Hosts page: each card shows the runners that have a container there (busy / idle / starting / stopping) and expands in place to list them; the page now also re-renders on `runner.state-changed`.
- Pool meters already followed `runner.state-changed`; a test now covers it.

## Task 48: Secrets and environment

- Table `secrets`: scope (`global`, `installation`, `pool`), kind (`env` or `registry`), name, encrypted `value`. `ResolveSecretsForPool` implements both `ResolvesSecrets` (runner env, registry credentials) and the log masker's `SecretSource`; precedence pool > installation > global per name.
- **Registry credentials** are a secret of kind `registry` whose name is the registry host (`ghcr.io`; `docker.io` for images without a host). They travel as `registry_auth` in `runner.start` and `image.pull`; the agent sends them to Docker in `X-Registry-Auth`. This closes the anonymous-pull gap of Task 39. Not tried against a real private registry.
- **Rotation**: saving an existing scope + name replaces the value and keeps the old one in `previous_value`. Runners already started keep the value they were given (it is in their container env), so both values are masked in logs until the next rotation.
- `env` and `registry_auth` are removed from the stored `host_commands` payload when the command is acknowledged, like `jit_config`. **While a command is pending they are in that table unencrypted**; encrypting the outbox payload is left for the hardening task.
- Access: global secrets need an administrator; installation secrets an operator of the installation; pool secrets an operator of the pool. Viewers see names only. Nobody can read a value back; the Livewire component drops it from its state as soon as it is saved. Env names starting with `GITHUB_`, `RUNNER_`, `ACTIONS_` and `JIT_CONFIG` are refused.
- Docker Desktop had stopped during this task and was started again to run the tests.

## Task 49: Tool-cache volumes and host build-cache policy

- A pool with "Keep the tool cache between jobs" sends `tool_cache_volume: jogger-toolcache-{pool id}` in `runner.start`. The agent creates that named volume if missing and mounts it at `/opt/hostedtoolcache`. One volume per pool per host; the agent refuses any name without that prefix, so the server can never make it mount a host path or another volume.
- The volume is labelled `jogger.tool-cache`, not with the host label, so the leftover-volume prune leaves it alone when no runner is using it. Nothing deletes it when the pool is deleted (left for later).
- `jogger:prune-hosts` (daily 03:45) sends `host.prune` with `max_build_cache_gb` (`JOGGER_BUILD_CACHE_MAX_GB`, default 20, 0 = leave alone) to reachable hosts; the agent calls Docker's build prune with `keep-storage`. The cap is instance-wide config for now, not a per-host setting. The agent's volume test ran against the local Docker engine; build-cache pruning was only unit-tested for the query it sends.

## Task 50: Metrics rollups and Insights page

- `metric_samples` holds minute and hour buckets for the instance, each pool and each repository (the instance uses the all-zero UUID as subject so the column fits in the primary key). `jogger:rollup-metrics` runs every minute: it recomputes the last two minutes and the current hour, settles the previous hour just after it ends, and prunes minutes after 2 days and hours after 400. `--backfill-hours=N` recomputes past hours.
- Queue wait p50/p95 use PostgreSQL `percentile_cont` over the jobs that started in the bucket, so they are exact per bucket. Runner counts are a snapshot taken when the minute is rolled up (past minutes cannot be rebuilt); an hour averages its minutes.
- **Charts are server-rendered SVG (`x-ui.line-chart`), not uPlot.** No JavaScript, colours come from the theme tokens so dark mode follows, and each chart carries a text summary (latest and highest value) for screen readers. The cost: no hover tooltips or zoom. uPlot can replace the component later without touching the data.
- Non-administrators see the sum of the repositories (and pools) they may see; across several repositories their p50 is a job-weighted average and their p95 the worst bucket, which are approximations.
- Ranges: 1 h reads minute samples, 24 h / 7 d / 30 d read hour samples (at most 720 rows per series through the primary key). The "< 1 s on 30 days of data" criterion was not measured.
- CSV export covers the repositories table of the selected range.

## Task 51: Prometheus endpoint

- Hand-written exporter (`PrometheusExporter`), no package. `GET /metrics` needs `Authorization: Bearer $JOGGER_METRICS_TOKEN` (compared with `hash_equals`); without a configured token the route answers 404; it is throttled to 60 requests a minute.
- Series: `jogger_runners{state}`, `jogger_jobs{status}`, `jogger_queue_depth`, `jogger_queue_wait_seconds` (histogram over jobs started in the last hour), `jogger_host_up`, host CPU / memory / disk gauges, `jogger_github_rate_limit_remaining{installation}`.
- The format is checked by a strict parser in the test, not by `promtool` (not installed here, and CI has no step for it).

## Task 52: Notifications and alert rules

- Alerts are **state-based**: `jogger:evaluate-alerts` (every minute) lists the conditions that hold now (`DetectConditions`) and compares them with the open rows in `alerts`. New ones fire, cleared ones resolve; so an alert is noticed within about a minute, not instantly.
- Conditions: host offline; host with less than 10 GB free (`JOGGER_ALERT_DISK_FREE_GB`); pool with 3 or more runners that failed to start in 10 minutes and none started since; an installation for which GitHub answered 401 even with a fresh token in the last 15 minutes; 5 or more failed webhook deliveries in 15 minutes.
- **Dedupe**: one row per (type, subject). If it fires again within 60 minutes (`JOGGER_ALERT_DEDUPE_MINUTES`) of its last notice, neither that firing nor its resolution is announced. There are no reminders while an alert stays open.
- Channels: in-app (always; the bell in the header, administrators only, read state per user), e-mail and generic webhook via rules in Settings → Notifications. Webhook bodies are signed `X-Jogger-Signature-256: sha256=<HMAC-SHA256>` with a per-rule secret shown once. A channel that fails does not stop the others: the error is stored on the rule and on the in-app notice.
- Mail and webhooks are sent inline by the evaluator (5 s webhook timeout), not queued. Webhook URLs are not restricted to public addresses; only administrators can add them.
- The dashboard query-budget test now measures the dashboard component alone, since the layout gained the bell (2 queries for administrators).

## Task 53: Settings, retention and audit log UI

- `settings` table (key, JSON value) and a `Settings` service. Saved values are laid over the config at boot (cached forever, dropped on save), so existing code keeps reading `config()`; an unsaved setting leaves the env variable in charge, a saved one wins over it.
- Editable at Admin → Settings: instance name, default runner image, retention of logs / finished jobs / webhook deliveries, Docker build cache cap per host. Each save records `settings.updated` with old and new values.
- Admin → Audit log: filter by actor, action and date range; CSV export of exactly the filtered rows.
