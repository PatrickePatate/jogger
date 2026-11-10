# Troubleshooting

Start with two places: `GET /health` signed in as an administrator (database, Redis, websockets, queue, GitHub credentials, hosts), and the container logs:

```bash
docker compose -f docker/compose/docker-compose.yml --env-file .env logs --tail 100 app horizon scheduler reverb
```

Every error response carries a `request_id`; the same id is in the server logs.

## Installation

**The `app` container restarts with "APP_KEY is not set".** Generate a key and put it in `.env`; see [INSTALL.md](INSTALL.md).

**The page loads without styles, or scripts are blocked.** Check that `APP_URL` matches the address in the browser, including `https`. If the browser console shows Content-Security-Policy errors, set `JOGGER_CSP_REPORT_ONLY=true`, recreate the containers, and report the blocked resource.

**Pages do not update live.** The browser cannot reach the websocket service. Behind your own proxy, forward `/app/*` and `/apps/*` to port 8080 with websocket upgrade enabled, or set `REVERB_PUBLIC_PORT` to the published Reverb port. Pages still refresh by polling every few seconds meanwhile.

## GitHub

**No webhook arrives (GitHub → Deliveries stays empty).** `APP_URL` must be HTTPS and reachable from the internet. On GitHub, open the app's **Advanced → Recent Deliveries** to see the error GitHub received. Check the webhook URL is `APP_URL/webhooks/github`.

**Deliveries are rejected with "invalid signature".** The webhook secret in the GitHub App differs from the one stored in Jogger. Set a new secret on GitHub and reconnect the app in Jogger with the same value.

**"GitHub rejected our credentials".** The app's private key was revoked, the app was uninstalled or suspended, or the server clock is wrong by more than a minute. Check the clock first (`date -u`), then the app on GitHub.

**"GitHub denied access: the GitHub App lacks a permission".** The app needs Administration (read and write) on repositories and Self-hosted runners (read and write) on organizations; see [GITHUB-APP.md](GITHUB-APP.md). After changing permissions, the organization owner must accept them.

## Jobs

**A job stays queued.** Open the job in Jogger; it states the reason:

- *No matching pool*: no active pool contains all the labels in `runs-on`. Labels must match exactly. Use **Create pool for these labels** on the Pools page.
- *No capacity*: the pool is at its maximum or no eligible host is online.
- No reason shown and no runner starting: check that the `horizon` and `scheduler` containers are running.

**The job does not appear in Jogger at all.** The repository is not covered by the app installation, or the webhook did not arrive. Jogger also asks GitHub for queued jobs every minute; if the job is still missing after that, check the installation's repository list.

**A job is queued on GitHub with "Waiting for a runner" although a runner started.** For organization pools, the repository must be allowed to use the organization's runner group (GitHub: organization **Settings → Actions → Runner groups**).

## Runners

**Runners fail with `start_failed`.** The host could not create the container. Most often the image cannot be pulled: check the image name on the pool, and for a private image add registry credentials under **Secrets**. The pool's edit page shows the pull error per host.

**Runners fail with `provision_timeout`.** The container started but did not register with GitHub in time. The host probably cannot reach GitHub, or the image is not a GitHub Actions runner image.

**Runners fail with `oom`.** The job used more memory than the pool's limit. Raise the limit.

**`docker` commands fail inside a job.** Set the pool's "Docker inside jobs" to Docker-in-Docker. The host must support user namespaces.

## Hosts

**A host stays Pending or goes Offline.** On the host:

```bash
docker logs --tail 50 jogger-agent
```

Typical causes: the server URL is wrong or not reachable from the host, the enrollment token expired (60 minutes) or was already used, or the host was revoked. Create a new token with **Add host** and start the agent again.

**"permission denied" on the Docker socket.** Add `--group-add <gid>` with the group id of `/var/run/docker.sock` to the agent's `docker run` command.

**A host runs out of disk.** Lower `JOGGER_BUILD_CACHE_MAX_GB`, and run `php artisan jogger:prune-hosts` to clean now.

## Recovery

**Lost `APP_KEY`.** The stored GitHub App credentials and secrets cannot be decrypted. Set a new key, reconnect the GitHub App, and enter the secrets again.

**Everything looks stuck after a restart.** Run `php artisan jogger:reconcile` in the `app` container to compare jobs and runners with GitHub immediately.
