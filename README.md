# Jogger

> Your GitHub Actions runners, on your own hardware.

Jogger is a self-hosted platform that runs your GitHub Actions jobs on your own Docker hosts. For every job it starts a fresh, isolated runner container, lets it run that one job, and destroys it. You keep your workflows exactly as they are and change only `runs-on`.

```yaml
jobs:
  build:
    runs-on: [self-hosted, linux, x64]
```

Jogger is not affiliated with or endorsed by GitHub.

> **Status: pre-release.** The features below are implemented and covered by automated tests against a simulated GitHub. A full run against real GitHub has not been done yet, and no image has been published. See [docs/TASKS.md](docs/TASKS.md) for what remains before 1.0.

## What it does

- **Ephemeral runners**: one container per job, registered with GitHub through a single-use configuration, never reused.
- **Pools**: label-matched groups of runners per repository or organization, with CPU and memory limits, a maximum size, and optional warm runners for instant starts.
- **Any number of hosts**: a small Go agent on each Docker host connects out to the server; hosts can sit behind NAT. Cordon, drain or revoke them from the UI.
- **Live UI**: jobs, runners, pools and hosts update in real time; job logs stream as they run and are kept afterwards.
- **Teams and roles**: grant teams access to installations or pools as viewer or operator; API tokens; audit log; optional two-factor and GitHub sign-in.
- **Secrets**: encrypted environment variables and registry credentials per pool, installation or instance, masked in logs.
- **Insights and alerts**: queue wait, usage and success rate; alerts in the app, by e-mail or webhook; a Prometheus endpoint.
- **Isolation by default**: non-root containers, dropped capabilities, private network per runner, no host Docker socket in jobs; optional rootless Docker-in-Docker.

## How it fits together

```
 GitHub ──webhooks──▶  Jogger server  ◀──HTTPS + websocket──  agent ──▶ Docker ──▶ runner containers
        ◀──API (runner registration)──┘                        (one per host)        (one per job)
```

The server is a Docker Compose stack (web app, websocket service, workers, scheduler, PostgreSQL, Redis). Runners talk to GitHub directly; job code and GitHub secrets never pass through the server.

## Getting started

1. [Install the server](docs/INSTALL.md)
2. [Connect GitHub](docs/GITHUB-APP.md)
3. [Add a host](docs/AGENT.md)
4. [Create a pool](docs/POOLS.md) and point a workflow at its labels

## Documentation

| Guide | Contents |
|---|---|
| [INSTALL.md](docs/INSTALL.md) | Installing, upgrading, backups, health checks |
| [CONFIGURATION.md](docs/CONFIGURATION.md) | Every setting and maintenance command |
| [GITHUB-APP.md](docs/GITHUB-APP.md) | Creating and connecting the GitHub App |
| [AGENT.md](docs/AGENT.md) | Hosts and the agent |
| [POOLS.md](docs/POOLS.md) | Pools, runners, logs, secrets |
| [TROUBLESHOOTING.md](docs/TROUBLESHOOTING.md) | Common problems and what to check |
| [API.md](docs/API.md) · [openapi.yaml](docs/openapi.yaml) | HTTP API |
| [SECURITY.md](docs/SECURITY.md) | Trust boundaries and safe operation; read before serving public repositories |

For contributors: [CONTRIBUTING.md](CONTRIBUTING.md), [SPECIFICATION.md](docs/SPECIFICATION.md), [IMPLEMENTATION.md](docs/IMPLEMENTATION.md), [TASKS.md](docs/TASKS.md), [DECISIONS.md](docs/DECISIONS.md), [E2E.md](docs/E2E.md).

## Stack

Laravel 13 · Livewire 4 · Alpine.js · Tailwind CSS 4 · Laravel Reverb · PostgreSQL 17 · Redis 7 · a Go agent on each Docker host.

## License

[MIT](LICENSE)
