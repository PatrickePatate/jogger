# Release checklist: v1.0.0

State on 2026-11-12. Nothing below has been ticked: tick a box only after doing the step and seeing the result.

## 1. Before tagging v1.0.0

- [ ] **PHP suite green**: `make test` on PostgreSQL + Redis. Run it with the `pcntl` extension loaded, otherwise
      `ConcurrencyTest` (the max-runners invariant) skips itself and proves nothing.
- [ ] **Go suite green**: `make agent-test` with a reachable Docker engine and the `alpine:latest` and
      `docker:dind-rootless` images pulled. Without them the Docker integration tests skip (orphan removal, stop,
      limits). Set `DOCKER_SOCKET` if the engine is not on `/var/run/docker.sock`. Check the output for `SKIP`.
- [ ] **Browser suite green**: `tests/Browser/run.sh` (needs `npm run build`, `php artisan dusk:chrome-driver`,
      `.env.dusk.local`). Passed locally on 2026-11-12 (6 tests); the CI `browser` job has not run yet.
- [ ] **CI green on `main`** (`.github/workflows/ci.yml`), on the commit that will be tagged.
- [ ] **Real GitHub end-to-end run (Task 40)**: follow `docs/E2E.md`, fill in its "Results" table, record fixes
      under "Fixes made because of this run". Never performed so far.
- [ ] **Soak test**: 50 concurrent runners across at least 2 hosts for 1 hour, nothing leaked (procedure below).
- [ ] **Pre-release dry run of the release workflow** (`docs/RELEASING.md`):
      `gh release create v1.0.0-rc.1 --prerelease --generate-notes`, then check that the "Release" and runner image
      workflows pass, that `jogger:<version>` and `jogger-agent:<version>` exist for amd64 and arm64, that `:latest`
      did not move, that the release has both agent binaries, the compose bundle and `SHA256SUMS`, and that
      `sha256sum -c SHA256SUMS --ignore-missing` and `jogger-agent --version` agree with the tag.
- [ ] **Packages public**: `jogger`, `jogger-agent` and `jogger-runner` under `ghcr.io/patrickepatate/` are public
      and allow the repository to write to them. Check with `docker logout ghcr.io && docker pull
      ghcr.io/patrickepatate/jogger:<version>`.

### Soak test procedure

Setup (after the Task 40 run works):

1. Two agent hosts enrolled and *Online*, each with CPU and memory for at least 25 runners at the pool's limits.
2. One pool on the test repository: labels `self-hosted, linux, x64`, `max_runners = 50`, `min_idle = 0`, both
   hosts allowed, image already pulled on both ("Image on hosts" shows *Ready* twice).
3. A workflow in the test repository, `.github/workflows/jogger-soak.yml`:

   ```yaml
   name: jogger-soak
   on: workflow_dispatch
   jobs:
     soak:
       runs-on: [self-hosted, linux, x64]
       strategy:
         fail-fast: false
         matrix:
           n: [1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,19,20,21,22,23,24,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,50]
       steps:
         - run: echo "job ${{ matrix.n }} on $(hostname)" && sleep 240
   ```

Run: dispatch a wave of 50 jobs every 5 minutes for one hour (12 waves, 600 jobs):

```bash
for i in $(seq 1 12); do gh workflow run jogger-soak.yml -R OWNER/REPO; sleep 300; done
```

During the run, on each host, the count must never pass the host's share and the two hosts together never pass 50:

```bash
watch -n 10 'docker ps --filter label=jogger.runner -q | wc -l'
```

Afterwards, wait 5 minutes after the last job finished, then check:

| Check | Command | Expected |
|---|---|---|
| Containers, each host | `docker ps -a --filter label=jogger.runner -q \| wc -l` | `0` |
| Networks, each host | `docker network ls --filter label=jogger.runner -q \| wc -l` | `0` |
| Volumes, each host | `docker volume ls -q` before and after | no new volumes except the pool's tool-cache volume, if enabled |
| GitHub runners | `gh api repos/OWNER/REPO/actions/runners --paginate --jq '[.runners[] \| select(.name \| startswith("jogger-"))] \| length'` | `0` |
| GitHub runs | `gh run list -R OWNER/REPO -w jogger-soak.yml -L 20 --json conclusion --jq 'map(.conclusion) \| unique'` | `["success"]` |
| Jogger runners | Runners page, or `GET /api/v1/runners` | no runner left in `provisioning`, `idle`, `busy` or `terminating` |
| Jogger jobs | Jobs page, status filter | 600 completed, none queued or unmatched |
| Both hosts used | Jobs page, host filter | jobs on both hosts |

Record date, commit, host sizes, peak concurrent runners and any leftover in this file under "Known gaps" or in
`docs/E2E.md`.

## 2. Traceability (SPEC §3)

One row per acceptance criterion. "automated" means the cited test was read and asserts the behaviour; a note says
what it leaves out. "manual" means a person has to check it (all or the named part). "gap" means not built or not
met. Paths are relative to `tests/` for PHP and to `agent/internal/` for Go.

| SPEC | Criterion | Covered by | Status |
|---|---|---|---|
| 3.1.1 | Manifest flow creates the app and stores id, key, secrets | `Feature/Github/ManifestFlowTest::test_a_valid_code_stores_the_app_with_encrypted_secrets_and_is_audited` (GitHub faked) | automated |
| 3.1.1 | Installing creates an `Installation` with its repositories | `Feature/Github/InstallationSyncTest::test_created_stores_the_installation_and_its_repositories`, `::test_the_whole_path_works_from_a_real_signed_webhook_request` | automated |
| 3.1.1 | Add/remove/suspend events update Jogger within 10 s | Handlers: `InstallationSyncTest::test_suspend_and_unsuspend_toggle_the_status`, `::test_deleted_marks_the_installation_deleted_and_removes_its_repositories`. The 10 s delay against real GitHub is not measured | manual |
| 3.1.1 | Private key and secrets stored encrypted | `Feature/Github/GithubModelsTest::test_app_secrets_are_encrypted_at_rest_and_readable_through_the_model` | automated |
| 3.1.2 | Invalid signature: 401, logged, no processing | `Feature/Github/WebhookTest::test_a_bad_or_missing_signature_is_a_401_and_nothing_is_stored_or_queued` (the log line is not asserted) | automated |
| 3.1.2 | Redelivered id acknowledged, processed once | `WebhookTest::test_the_same_delivery_twice_gets_200_both_times_and_is_processed_once` | automated |
| 3.1.2 | Responds under 500 ms, processing asynchronous | `WebhookTest::test_the_endpoint_answers_in_well_under_half_a_second`, `::test_a_valid_signed_delivery_is_stored_and_queued_without_running_handlers_inline` | automated |
| 3.1.2 | Deliveries kept 14 days, visible in UI, replay | `Feature/Github/PruneTest::test_deliveries_older_than_the_retention_period_are_deleted`, `Feature/Github/DeliveriesPageTest::test_the_replay_button_queues_the_delivery_and_marks_it_in_the_list`, `::test_a_replay_runs_the_handler_again_end_to_end` | automated |
| 3.1.3 | Every 60 s, missing queued jobs imported and scheduled | `Feature/Github/ReconcileTest::test_a_queued_job_missing_locally_is_imported_and_scheduled` (the `everyMinute` schedule in `routes/console.php` is not asserted) | automated |
| 3.1.3 | Unknown Jogger-prefixed runners removed from GitHub | `ReconcileTest::test_unknown_and_finished_jogger_runners_are_deleted_from_github` | automated |
| 3.1.3 | Runner missing in GitHub > 5 min: failed, container destroyed | `ReconcileTest::test_a_runner_missing_on_github_for_five_minutes_is_failed_and_stopped` | automated |
| 3.2.1 | Pool has all listed fields | Partly: `Feature/Pools/PoolsTest::test_validation_rules` (name, scope, labels, image, min idle, max). No test saves and reads back CPU/memory limits, idle TTL, allowed hosts, runner group | manual |
| 3.2.1 | Configuration validated | `PoolsTest::test_validation_rules` (max ≥ min, labels, image syntax). "Image resolvable" is not checked on save: a failed pull shows up per host afterwards | automated |
| 3.2.1 | Changes apply to new runners only | No test edits a pool with live runners. Related only: `Feature/Secrets/SecretsTest::test_rotation_reaches_new_runners_only_and_both_values_stay_masked` | manual |
| 3.2.1 | Pause/resume; paused pools provision nothing | `PoolsTest::test_pause_and_resume`, `Feature/Pools/WarmPoolTest::test_paused_pool_provisions_nothing_and_rate_limits_stop_the_pass` | automated |
| 3.2.2 | Default image follows new `actions/runner` releases | None. `.github/workflows/runner-image.yml` has a weekly cron and has never run | manual |
| 3.2.2 | Pull progress and failures visible per host | `Feature/Pools/ImagePullTest::test_pull_results_are_tracked_per_host_and_a_failure_blocks_nothing` (pending / ready / failed with error; no percentage) | automated |
| 3.2.2 | Private registry credentials | `SecretsTest::test_runner_start_carries_env_and_registry_credentials_and_they_are_scrubbed_on_ack` | automated |
| 3.3.1 | One job per runner, destroyed afterwards with volumes | `Feature/Scheduling/EndToEndFakeTest::test_full_cycle_from_queued_to_torn_down`, Go `docker/runner_test.go::TestStopRemovesContainerAndNetwork` (container and network; volume removal not asserted; never run with a real runner) | automated |
| 3.3.1 | Median queued → running under 15 s | None. Measured in the Task 40 run (`docs/E2E.md`) | manual |
| 3.3.1 | Never more than max concurrent runners | `Feature/Scheduling/ConcurrencyTest::test_fifty_concurrent_requests_never_exceed_max_runners` (skips without `pcntl`) | automated |
| 3.3.1 | Min-idle runners maintained | `WarmPoolTest::test_pool_converges_to_min_idle_and_stays_there` | automated |
| 3.3.1 | Stuck in `provisioning` > 3 min: killed, rescheduled | `Feature/Runners/ReaperTest::test_provision_timeout_fails_the_runner_and_reprovisions_for_the_waiting_job` | automated |
| 3.3.1 | Job cancelled before pick-up: runner removed within 60 s | `EndToEndFakeTest::test_cancelled_while_queued_trims_the_unused_runner_within_a_minute` | automated |
| 3.3.2 | Placement uses free CPU/memory, pool constraints, host labels | `Unit/PickHostTest::test_skips_hosts_without_capacity`, `::test_honors_host_labels_and_architecture_and_prefers_the_emptiest` (labels only; there is no separate taint concept) | automated |
| 3.3.2 | Cordon and drain | `Feature/Hosts/OperationsTest::test_cordon_and_uncordon`, `::test_drain_waits_for_the_last_runner_then_cordons` | automated |
| 3.4.1 | One-time enrollment token and copy-paste command | `Feature/Hosts/HostsTest::test_the_enroll_modal_shows_a_docker_command_with_token_and_server_url`, `Feature/Agent/EnrollTest::test_a_token_can_only_be_used_once` | automated |
| 3.4.1 | Agent connects outbound over TLS, no inbound ports | Partly: Go `commands/dispatcher_test.go::TestPollingWorksWithoutPushes` (agent pulls). TLS and a firewalled host are not tested; real Reverb never exercised | manual |
| 3.4.1 | Reports Docker version, CPU, memory, disk, arch; heartbeat every 10 s | Go `telemetry/heartbeat_test.go::TestCollectUsesProcFilesAndComputesCPUFromDeltas`, `config/config_test.go::TestDefaults` (10 s), `HostsTest::test_heartbeat_updates_capacity_and_marks_a_pending_host_online` | automated |
| 3.4.1 | Offline after 30 s; runners reconciled on return or failed after 5 min | `HostsTest::test_hosts_silent_for_30_seconds_go_offline_and_return_on_heartbeat`, `ReaperTest::test_runners_on_a_host_offline_for_five_minutes_are_lost`, `Feature/Runners/TransitionTest::test_agent_gets_the_full_list_of_runners_it_should_have` | automated |
| 3.4.1 | Revoking a host invalidates credentials at once | `OperationsTest::test_revoke_kills_the_credential_and_pending_commands` | automated |
| 3.4.1 | Local Docker socket as a host, off by default | `OperationsTest::test_local_host_is_off_by_default_and_enrolls_when_enabled` (the compose `local-host` profile was never started) | automated |
| 3.4.2 | Control plane announces agent upgrades; agent self-updates or flags it | Agent side only: Go `update/update_test.go::TestCheckerReportsOnlyNewerVersions`, `::TestApplyRefusesBadChecksumAndMissingDigest`. No `version` route found in `routes/`, and the server does not store or show `update_available` | gap |
| 3.4.2 | Agent refuses commands not signed or authorized | Partly: Go `enroll/enroll_test.go::TestCredentialFromAnotherServerIsRefused`, `Feature/Agent/CommandsTest::test_commands_need_a_valid_credential`. Commands are not signed; trust rests on TLS to the enrolled server | manual |
| 3.4.2 | Orphaned Jogger containers removed after 10 min | Go `docker/housekeeping_test.go::TestOrphanRemovalSparesKnownAndYoungRunners` (skips without Docker and `alpine`) | automated |
| 3.5.1 | Status change in UI within 2 s, no reload | `Feature/Livewire/JobsIndexTest::test_a_status_change_shows_up_on_the_next_event_without_reload`, `Browser/JobLiveUpdateTest` (2 s wait, real Reverb) | automated |
| 3.5.1 | Job shows repo, workflow, run link, branch/SHA, actor, labels, pool, host, times, conclusion | Partly: `Feature/Livewire/JobShowTest::test_steps_follow_the_job_as_events_arrive` (pool, runner, host, timings, conclusion). Branch/SHA, actor, labels not asserted | manual |
| 3.5.1 | Links to the run on GitHub | `JobShowTest::test_github_link_opens_in_a_new_tab_and_the_log_viewer_is_wired` | automated |
| 3.5.2 | Log lines appear within 1 s | Go `logs/shipper_test.go::TestOutputIsShippedWithinOneSecond`, `Browser/LogStreamTest::log_lines_appear_in_the_terminal_within_a_second` | automated |
| 3.5.2 | Stored compressed, configurable retention (default 30 d) | `Feature/Logs/IngestionTest::test_eof_gzips_the_log_and_sets_expiry` (retention set to 7; the default of 30 is not asserted) | automated |
| 3.5.2 | ANSI colours render; known secrets masked | Masking: `IngestionTest::test_secrets_are_masked_before_storage_and_broadcast`. ANSI rendering has no test | manual |
| 3.5.2 | Download as text; search in page | Download: `Feature/Logs/RetrievalTest::test_scrollback_and_download_work_for_a_finalized_log`. In-page search has no test | manual |
| 3.5.2 | Constraint: per-job log cap, truncated with a marker | `IngestionTest::test_log_over_the_limit_is_truncated_with_a_marker` | automated |
| 3.6 | Secrets encrypted, never shown after save, never in logs | `SecretsTest::test_values_are_encrypted_at_rest_and_hidden`, `::test_secret_values_are_masked_in_job_logs` | automated |
| 3.6 | Precedence pool > installation > global | `SecretsTest::test_narrower_scopes_override_wider_ones` | automated |
| 3.7 | `actions/cache` works against the local backend | Not built (Tasks 54–55 deferred to 1.1) | gap |
| 3.7 | Cache size cap and LRU eviction per installation | Not built | gap |
| 3.7 | Caches isolated by repository | Not built. Only the per-pool tool-cache volume exists (`Feature/Pools/ToolCacheTest`) | gap |
| 3.8.1 | First-run setup creates the initial admin | `Feature/Setup/FirstRunWizardTest::test_creating_the_admin_logs_them_in_and_moves_to_the_url_check` | automated |
| 3.8.1 | Password login, TOTP 2FA, recovery codes, reset by email | `Feature/Auth/TwoFactorLoginTest::test_a_valid_totp_code_completes_the_login`, `::test_recovery_codes_work_exactly_once`, `Feature/Auth/PasswordResetTest::test_reset_link_flow_changes_the_password` | automated |
| 3.8.1 | Sign in with GitHub, by verified email or explicit link | `Feature/Auth/GithubLoginTest::test_a_linked_github_id_signs_in_directly_and_regenerates_the_session`, `::test_confirming_the_password_links_the_account_and_signs_in` (OAuth faked; real GitHub round trip untested) | automated |
| 3.8.1 | Login throttling, 5 failures per minute | `Feature/Auth/LoginTest::test_sixth_failed_attempt_in_a_minute_is_throttled` | automated |
| 3.8.2 | Roles per team; teams granted installations/pools | `Feature/VisibilityTest::test_role_for_is_the_highest_role_across_teams_holding_a_grant` | automated |
| 3.8.2 | Users see only what their teams grant; admins see all | `VisibilityTest::test_user_sees_only_resources_granted_to_their_teams_across_two_teams`, `::test_admin_sees_everything_and_is_admin_everywhere` | automated |
| 3.8.2 | Every state-changing action writes an audit entry | Mechanism: `Feature/Access/AuditTest::test_record_stores_actor_target_metadata_and_ip`; many actions assert their entry, but no test proves that every action does | manual |
| 3.9 | Dashboard: queue depth, wait p50/p95, utilization, jobs/day, success rate, minutes per repo/pool, host capacity | `Feature/Livewire/DashboardTest::test_tiles_reflect_the_seeded_data`, `Feature/Metrics/InsightsTest::test_the_page_shows_totals_and_the_export_matches_the_table`, `::test_rollup_yields_exact_wait_percentiles_and_counts` | automated |
| 3.9 | Time ranges 1 h, 24 h, 7 d, 30 d | Partly: `InsightsTest::test_the_page_shows_totals_and_the_export_matches_the_table` uses 24 h and 1 h. 7 d and 30 d are defined but untested | manual |
| 3.9 | Token-protected Prometheus `/metrics` | `Feature/Metrics/PrometheusTest::test_it_requires_the_token`, `::test_it_exposes_the_expected_series_in_valid_text_format` | automated |
| 3.10 | Alerts: host offline, pool cannot provision, GitHub auth failure, disk pressure, webhook failures | `Feature/Notifications/AlertsTest::test_host_offline_fires_once_then_resolves`, `::test_each_other_alert_type_fires_on_its_trigger` | automated |
| 3.10 | Channels: in-app, email, generic webhook | `AlertsTest::test_the_bell_counts_unread_notices_for_administrators`, `::test_mail_is_sent_to_the_rule_address`, `::test_the_webhook_channel_signs_its_payload` | automated |

Totals: 60 criteria, 44 automated, 12 manual, 4 gap. §3.11 (native workflow engine) is out of v1.0.

Every "automated" row is proven against a fake GitHub gateway and, for the agent, stand-in images. None of them
replaces the Task 40 run.

## 3. Known gaps

Not built or not met:

- **Cache service (SPEC §3.7) deferred to 1.1.** Task 54 (protocol spike) was not performed and Task 55 was not
  built. `JOGGER_CACHE_ENABLED` exists and does nothing. All three §3.7 criteria are unmet.
- **Agent upgrade announcement (SPEC §3.4.2).** The agent can check, flag and self-update, but the control plane
  has no version endpoint in `routes/` and does not store or display `update_available`.
- **Commands are not signed (SPEC §3.4.2).** The agent trusts whatever its enrolled server sends over TLS.
- **Pending command payloads are stored unencrypted** (secrets and registry credentials until the agent
  acknowledges, then scrubbed).
- **No screenshots**: `docs/img/` does not exist.
- **Task 59 (accessibility pass)** has no record of being done.

Never verified against the real thing:

- **No real GitHub run yet (Task 40).** No job has ever run on a Jogger runner. `tests/Script/e2e.sh` is
  syntax-checked only. Unproven until then: JIT config call, `runner_name` matching, the runner image, container
  hardening (`CapDrop ALL`, `no-new-privileges`), ephemeral teardown on GitHub's side, the 15 s latency target.
- **No soak test.** 50 runners on 2 hosts for an hour has not been tried; leak-freedom is unproven.
- **GitHub App manifest keys not verified**: `organization_self_hosted_runners` in `default_permissions` was
  written from memory. The email permission is not requested because its key could not be verified, so GitHub
  sign-in by email match fails closed when emails are unreadable.
- **Release workflow never run.** No pre-release dry run; the server image has never been built for arm64; the
  packages are private.
- **CI** was validated with actionlint and a local replay; confirm a green run on GitHub for the release commit.
- **Browser-only behaviour**: the browser tests (setup, live job update, log stream, accessibility) pass locally
  and saw no Content-Security-Policy violation, with websockets configured through `<meta name="jogger-reverb">`.
  ANSI rendering, in-page log search and the merge on reload have no test. The CI `browser` job has not run.
- **Accessibility**: axe (serious/critical) is clean on seven pages only; no screen reader test, no full keyboard
  walkthrough, modals and the command palette unchecked ([A11Y.md](A11Y.md)).
- **Performance** numbers are in-process on one laptop, without concurrency ([PERFORMANCE.md](PERFORMANCE.md)).
- **Agent against real Reverb** not exercised (tests use a fake Pusher endpoint); the agent falls back to polling
  every 15 s, which alone would miss the latency target.
- **Agent**: `docker build` through the DinD sidecar and self-update under a real supervisor are untested.
- **Runner image**: weekly rebuild workflow has never run; no real GitHub runner image has been tested.
- **Install**: nobody has followed INSTALL + GITHUB-APP to a first green job. Caddy (`--profile https`), the
  `local-host` profile and two replicas migrating at once were not tried. `openapi.yaml` was not run through an
  external linter. Troubleshooting advice comes from code paths, not incidents.
- **Installation suspend/unsuspend events arriving out of order** can leave the wrong status until the next
  event; resync does not refresh the installation's status.
- **Manual rows in the table**: 10 s installation latency, full pool field set, pool edits not disturbing live
  runners, TLS/outbound-only agent, job field list, audit completeness, 7 d / 30 d ranges.
