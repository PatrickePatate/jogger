# Contributing to Jogger

Thanks for helping. Bug reports, documentation fixes and code are all welcome. For anything larger than a small fix, open an issue first so the approach can be agreed before you invest time.

Security problems: do not open a public issue. See [docs/SECURITY.md](docs/SECURITY.md).

## Local setup

Requirements: PHP 8.3+, Composer, Node 22+, Go 1.25+ (for the agent), Docker, `make`.

```bash
composer install && cp .env.example .env && php artisan key:generate
```

```bash
make up
```

`make up` starts PostgreSQL (5432) and Redis (6380) in Docker and creates the test database. If those ports are taken, set `FORWARD_DB_PORT` / `FORWARD_REDIS_PORT` and update `DB_PORT` / `REDIS_PORT` in `.env`.

```bash
php artisan migrate && npm install && npm run build
```

```bash
composer run dev
```

This runs the web server, Horizon, Reverb and Vite together. Open <http://localhost:8000>; the first visit creates the administrator. In the local environment `/_ui` shows the component kit.

The agent lives in `agent/`:

```bash
cd agent && go build ./... && go test ./...
```

Some agent tests need a local Docker Engine and skip themselves without one.

## Checks

Run these before opening a pull request; CI runs the same.

```bash
make lint
```

```bash
make stan
```

```bash
make test
```

`make lint` is Pint (code style), `make stan` is Larastan at level 6, `make test` is PHPUnit and needs `make up`. For the agent: `gofmt -l .`, `go vet ./...`, `go test ./...`.

## How the code is organised

- `app/Domain/<Area>` holds the logic, one small **Action** class per operation (`ProvisionRunner`, `MatchPool`, …). Controllers, Livewire components and commands stay thin and call Actions.
- State changes of jobs, runners and hosts go through their transition Actions, which use a conditional `UPDATE … WHERE state IN (…)`. Do not set a state column directly.
- Everything a user can list goes through a `visibleTo($user)` scope. New queries in pages or the API must use it.
- GitHub is reached only through the `GithubGateway` interface; tests use `FakeGithubGateway`.
- Commands for hosts go through the outbox (`HostDriver::send`), never directly.
- Read [docs/IMPLEMENTATION.md](docs/IMPLEMENTATION.md) for the architecture and [docs/DECISIONS.md](docs/DECISIONS.md) for why things differ from the original plan.

## Conventions

- Tests come with the change. Feature tests use a real PostgreSQL database; fake GitHub and the queue, not the database.
- User-facing text is plain and specific: say what happened and what to do next. See [docs/BRANDING.md](docs/BRANDING.md).
- A new API route must be added to `docs/openapi.yaml`, and a new production environment variable to `docs/CONFIGURATION.md`; tests enforce both.
- A new `encrypted` model attribute must be added to `RotateAppKey::COLUMNS`; a test enforces it.
- Keep commits focused, with a message that says what changed and why.

By contributing you agree that your contribution is licensed under the [MIT License](LICENSE).
