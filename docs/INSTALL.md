# Installing Jogger

Jogger runs as a Docker Compose stack: the web app, a websocket service (Reverb), queue workers (Horizon), a scheduler, PostgreSQL and Redis. The machines that run jobs ("hosts") are separate and are added from the UI afterwards.

## Requirements

- A Linux server with Docker Engine 24+ and the Compose plugin; 2 CPU cores and 2 GB of memory are enough for the server itself.
- A public HTTPS address for it. GitHub must be able to reach `/webhooks/github`, and hosts must reach the server.

## Steps

1. Get the compose files (clone the repository, or copy `docker/compose`, `docker/caddy` and `.env.production.example`).
2. Create the configuration:

   ```bash
   cp .env.production.example .env
   ```

   ```bash
   docker run --rm ghcr.io/patrickepatate/jogger:latest php artisan key:generate --show
   ```

   Put the printed key in `APP_KEY`, then set `APP_URL`, `DB_PASSWORD`, `REVERB_APP_KEY` and `REVERB_APP_SECRET` (any long random strings). **Back up `APP_KEY`**: it encrypts the GitHub App key and all secrets.
3. Start it. With automatic HTTPS (set `JOGGER_DOMAIN` first; ports 80 and 443 must be free):

   ```bash
   docker compose -f docker/compose/docker-compose.yml --env-file .env --profile https up -d
   ```

   Behind your own reverse proxy, omit `--profile https` and proxy `/app/*` and `/apps/*` to port 8080 (websockets) and everything else to port 8000.
4. Open `APP_URL`. The first visit shows the setup page: create the administrator, then follow the checklist on the overview (connect a GitHub App, add a host, create a pool).

Database migrations run automatically each time the `app` container starts.

## Running jobs on the server itself

Not recommended for anything but a trial: the agent container controls the server's Docker. Set `JOGGER_LOCAL_HOST=true` and `JOGGER_LOCAL_HOST_TOKEN` (`jhe_` followed by at least 32 random characters), set `DOCKER_GID` to the group id of `/var/run/docker.sock`, and add `--profile local-host`.

## Upgrading

```bash
docker compose -f docker/compose/docker-compose.yml --env-file .env pull
```

```bash
docker compose -f docker/compose/docker-compose.yml --env-file .env up -d
```

## Backups

Back up the `postgres-data` volume (or use `pg_dump`), the `app-storage` volume (job logs), and `.env`. Redis holds only queues, cache and sessions.

## Checking health

- `GET /up` answers 200 when the web app is running (used by the container health check).
- `GET /health`, signed in as an administrator, lists the state of the database, Redis, Reverb, the job queue, GitHub credentials and hosts; it answers 503 with `"status": "degraded"` when any of them fails.
- `GET /metrics` exposes Prometheus metrics when `JOGGER_METRICS_TOKEN` is set.

See `docs/SECURITY.md` before serving public repositories.
