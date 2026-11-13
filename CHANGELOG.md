# Changelog

Notable changes per release. The full list of commits for a release is in its notes on GitHub, which are
generated when the release is created (see [docs/RELEASING.md](docs/RELEASING.md)).

## Unreleased

Cloud hosts: Scaleway, DigitalOcean, AWS EC2 and OpenStack (OVHcloud and others) drivers (Administration → Cloud). Tested with faked API responses only. Templates take provider options as JSON (AWS subnet, security groups, spot). Providers can be deactivated to stop new servers. The Cloud page is reorganised: lists first, forms in sections, credential help per driver.

## 1.1.1 - 2026-11-13

Agents can be updated from the Hosts page: a host running an older agent than the server shows "X available" and an "Update agent" button (host must have no active runner; drain it first). The agent pulls the new image and replaces its own container with one that has the same configuration. Needs agent 1.1.0 or later to receive the command, so the first jump from 1.0.0 is still manual. Server images now know their version (`JOGGER_VERSION`).

## 1.1.0 - 2026-11-13

Cloud hosts: a pool can scale with servers created at a cloud provider (Hetzner Cloud first). Jogger creates a
server when jobs wait and no host has a free slot, the agent enrolls, and the server is deleted once idle.
Templates set jobs per server, warm servers, caps, idle time and lifetime; an orphan sweep removes leaked servers.
Administration → Cloud manages providers and templates. See [docs/CLOUD-HOSTS.md](docs/CLOUD-HOSTS.md).
Verified on a real Hetzner project: create, enroll, run a full CI run, delete.

Also: jobs whose `completed` webhook was missed are reconciled against GitHub after 3 minutes, so they no longer
pin a runner (or a cloud server).

Not included: Scaleway, DigitalOcean, AWS and OpenStack drivers; the hourly-billing rule was not tried on a real server.

## 1.0.0 - 2026-11-12

First public version: ephemeral runners per job, pools with warm runners, multi-host agent, live UI and logs,
teams and roles, API, secrets, insights, alerts, Prometheus endpoint, Docker Compose install.

Not included: the Actions cache service (planned for 1.1).

Added since the first deployment: a per-pool option to allow `sudo` in jobs, step output in job logs, a host
setting for the Docker-in-Docker sidecar image (`JOGGER_DIND_IMAGE`), and service container ports on the job's
localhost.

Known limits: step output reaches Jogger when a step ends, not line by line; no soak test has been run; see
[docs/RELEASE-CHECKLIST.md](docs/RELEASE-CHECKLIST.md) for what was not verified.
