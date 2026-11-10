# Configuration

Jogger is configured with environment variables in `.env` (start from `.env.production.example`). After changing `.env`, recreate the containers:

```bash
docker compose -f docker/compose/docker-compose.yml --env-file .env up -d
```

A few settings can also be changed by an administrator in the UI (**Settings** in the sidebar). A value saved there takes precedence over the environment variable; they are marked "UI" below.

## Required

| Variable | Meaning |
|---|---|
| `APP_KEY` | Encryption key for the GitHub App credentials, secrets and sessions. Generate it once, back it up, never lose it. Rotate it with `php artisan jogger:rotate-key`. |
| `APP_URL` | Public address of the instance, for example `https://ci.example.com`. Used in links, in the GitHub App's webhook URL and by agents. Must be HTTPS for GitHub to deliver webhooks. |
| `DB_PASSWORD` | Password of the PostgreSQL user. Any long random string. |
| `REVERB_APP_KEY`, `REVERB_APP_SECRET` | Two long random strings that authenticate the server to its own websocket service. |

## General

| Variable | Default | Meaning |
|---|---|---|
| `APP_NAME` | `Jogger` | Name shown in page titles and e-mails. UI. |
| `DB_DATABASE`, `DB_USERNAME` | `jogger` | PostgreSQL database and user. |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `redis` | Leave as they are; the stack expects Redis. |
| `BROADCAST_CONNECTION` | `reverb` | Leave as it is. |
| `REDIS_CLIENT` | `phpredis` | Leave as it is. |
| `REVERB_APP_ID` | `jogger` | Identifier of the websocket application. |
| `REVERB_PUBLIC_HOST`, `REVERB_PUBLIC_PORT`, `REVERB_PUBLIC_SCHEME` | empty | Where browsers reach the websocket service. Empty means the page's own host, port and scheme, which is right behind Caddy (`--profile https`) or any proxy that forwards `/app/*` and `/apps/*` to Reverb. Without such a proxy set the port to the published Reverb port. |
| `JOGGER_DOMAIN` | | Domain Caddy requests a certificate for (`--profile https`). |
| `JOGGER_HTTP_PORT` | `8000` | Port the web app is published on. |
| `JOGGER_REVERB_PORT` | `8080` | Port the websocket service is published on. |

## E-mail

Used for invitations and alerts.

| Variable | Default | Meaning |
|---|---|---|
| `MAIL_MAILER` | `log` | `smtp` to send mail; `log` writes messages to the log instead. |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | | SMTP server settings. |
| `MAIL_FROM_ADDRESS` | | Sender address. |

## Runners and jobs

| Variable | Default | Meaning |
|---|---|---|
| `JOGGER_RUNNER_IMAGE` | `ghcr.io/jogger/jogger-runner:latest` | Image prefilled for new pools. UI. |
| `JOGGER_PROVISION_TIMEOUT` | `180` | Seconds a runner may take to start before it is considered failed. |
| `JOGGER_HOST_OFFLINE_AFTER` | `30` | Seconds without a heartbeat before a host is marked offline. |
| `JOGGER_RECONCILE_INTERVAL` | `60` | Seconds between comparisons of Jogger's state with GitHub's. |
| `JOGGER_BUILD_CACHE_MAX_GB` | `20` | Docker build cache each host may keep; trimmed daily. `0` leaves it alone. UI. |
| `JOGGER_LOCAL_HOST` | `false` | Allow an agent on the server itself (`--profile local-host`). |
| `JOGGER_LOCAL_HOST_TOKEN` | | Enrollment token for that agent: `jhe_` followed by at least 32 random characters. |

## Retention

Applied by a nightly clean-up.

| Variable | Default | Meaning |
|---|---|---|
| `JOGGER_LOG_RETENTION_DAYS` | `30` | How long job logs are kept. UI. |
| `JOGGER_JOB_RETENTION_DAYS` | `90` | How long finished jobs are kept. UI. |
| `JOGGER_DELIVERY_RETENTION_DAYS` | `14` | How long received webhooks are kept. UI. |
| `JOGGER_LOG_MAX_BYTES` | `104857600` | Maximum stored log size per job (100 MB); the log is cut with a marker beyond it. |
| `JOGGER_LOG_DISK`, `JOGGER_LOG_PATH` | `logs`, `storage/app/logs` | Where job logs are stored. |

## Monitoring and alerts

| Variable | Default | Meaning |
|---|---|---|
| `JOGGER_METRICS_TOKEN` | | Bearer token for `GET /metrics` (Prometheus). Unset disables the endpoint. |
| `JOGGER_ALERT_DEDUPE_MINUTES` | `60` | An alert that fires again within this time of its last notice stays quiet. |
| `JOGGER_ALERT_DISK_FREE_GB` | `10` | Free disk below which a host raises an alert. |
| `JOGGER_ALERT_WEBHOOK_FAILURES` | `5` | Failed webhooks in 15 minutes that raise an alert. |
| `JOGGER_ALERT_PROVISION_FAILURES` | `3` | Runners failing to start in 10 minutes that raise an alert for their pool. |

Scrape configuration example:

```yaml
scrape_configs:
  - job_name: jogger
    metrics_path: /metrics
    scheme: https
    authorization:
      credentials: <JOGGER_METRICS_TOKEN>
    static_configs:
      - targets: ["ci.example.com"]
```

## GitHub

| Variable | Default | Meaning |
|---|---|---|
| `JOGGER_GITHUB_WEB_URL` | `https://github.com` | Change for GitHub Enterprise Server. |
| `JOGGER_GITHUB_API_URL` | `https://api.github.com` | For Enterprise Server: `https://<host>/api/v3`. |
| `JOGGER_GITHUB_TIMEOUT` | `15` | Seconds before a GitHub API call is abandoned. |
| `JOGGER_GITHUB_RETRIES` | `3` | Attempts for a failing GitHub API call. |

## Security

| Variable | Default | Meaning |
|---|---|---|
| `JOGGER_CSP_REPORT_ONLY` | `false` | Send the Content-Security-Policy as report-only. Use temporarily if a page is blocked, and report it. |

## Maintenance commands

Run them in the `app` container, for example `docker compose ... exec app php artisan jogger:prune`.

| Command | Purpose |
|---|---|
| `jogger:install` | Run database migrations (done automatically at start). |
| `jogger:prune` | Delete data past its retention (nightly). |
| `jogger:prune-hosts` | Ask hosts to clean images and build cache (nightly). |
| `jogger:reconcile` | Compare jobs and runners with GitHub now. |
| `jogger:rollup-metrics --backfill-hours=N` | Recompute Insights data for past hours. |
| `jogger:evaluate-alerts` | Check alert conditions now. |
| `jogger:rotate-key` | Re-encrypt everything with a new `APP_KEY`. |
