# Jogger

> Your GitHub Actions runners, on your own hardware.

Jogger is a self-hosted platform that runs your GitHub Actions jobs on your own Docker hosts. It spins up a fresh, isolated runner for every job, scales with your queue, and shows everything live: what is waiting, what is running, and why something failed. One `docker compose up` to install, no Kubernetes required.

> **Status: under construction.** The foundation (UI kit, realtime plumbing, CI) is in place; GitHub integration, runners and the agent are being built task by task. See [docs/TASKS.md](docs/TASKS.md) for the plan and [docs/SPECIFICATION.md](docs/SPECIFICATION.md) for the product.

Jogger is not affiliated with or endorsed by GitHub.

## Stack

Laravel 13 · Livewire 4 · Alpine.js · Tailwind CSS 4 · Laravel Reverb · PostgreSQL 17 · Redis 7 · a Go agent on each Docker host.

## Local development

Requirements: PHP 8.3+, Composer, Node 22+, Docker (for PostgreSQL and Redis), `make`.

```bash
composer install && cp .env.example .env && php artisan key:generate
make up                      # PostgreSQL (5432) + Redis (6380) in Docker; also creates the test database
php artisan migrate && npm install
npm run build                # or `npm run dev` for hot reload
composer run dev             # web server, Horizon, Reverb and Vite together
```

Open <http://localhost:8000>. In the local environment, `/_ui` shows the component kit.

If something else already uses port 5432 or 6380, set `FORWARD_DB_PORT` / `FORWARD_REDIS_PORT` and update `DB_PORT` / `REDIS_PORT` in `.env` to match.

### Checks

```bash
make lint     # Pint (style)
make stan     # Larastan, level 6
make test     # PHPUnit (needs `make up`)
```

CI runs the same three commands plus a frontend build on every push and pull request (`.github/workflows/ci.yml`).

## Documentation

| Doc | What it is |
|-----|-----------|
| [SPECIFICATION.md](docs/SPECIFICATION.md) | What Jogger does |
| [IMPLEMENTATION.md](docs/IMPLEMENTATION.md) | How it is built |
| [TASKS.md](docs/TASKS.md) | Ordered work breakdown |
| [BRANDING.md](docs/BRANDING.md) | Name, colors, UI language |
| [DECISIONS.md](docs/DECISIONS.md) | Where the build deviated from the plan, and why |
