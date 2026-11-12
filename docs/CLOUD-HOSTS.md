# Cloud hosts (plan)

> Status: **planned, not implemented.** Decisions marked "decided" were made by the project owner on 2026-11-12.

A pool can be backed by a cloud template. When jobs wait and no host has a free slot, Jogger creates a server at a cloud provider, the agent on it enrolls, the jobs run, and Jogger deletes the server once it has been idle.

## Flow

1. A job is queued and matches a pool, but no online host has a free slot.
2. Jogger creates a single-use enrollment token (short lifetime, preset host name) and asks the provider for a server, with a cloud-init script that starts `jogger-agent` with that token.
3. The server boots and the agent enrolls. When the host comes online Jogger schedules the pool's waiting jobs again; from here it is an ordinary host.
4. When the host has had no runner for the template's idle time, Jogger cordons it, deletes the server through the provider and removes the host.

## Decided

- **Jobs per server** is a template setting (`max_runners_per_host`). 1 means one server per job, which is the strongest isolation.
- **Warm servers** is a template setting (`warm_hosts`, default 0). 0 is scale-from-zero: no server exists while nothing is queued.
- **Providers**: Hetzner Cloud first. Others are added after the owner validates the shortlist below.

## Data model

| Table | Columns (main) |
|---|---|
| `cloud_providers` | name, driver, credentials (encrypted, listed in `RotateAppKey::COLUMNS`), status |
| `host_templates` | provider, name, server type, location(s), image or snapshot, host labels, `max_runners_per_host`, `warm_hosts`, `max_hosts`, idle seconds, max lifetime, IPv4 on/off, hourly price (for estimates) |
| `host_template_pool` | which pools may scale with which templates |
| `hosts` (added) | template, provider server id, `ephemeral`, cloud state, billed-from time |

Cloud state of a host: requested → booting → online → draining → deleting → deleted, or failed. Transitions use the same conditional `UPDATE … WHERE state IN (…)` as runners.

## Components

- `CloudProvider` interface: `create(spec)`, `delete(id)`, `get(id)`, `listManaged()`, `validateCredentials()`. One class per provider, plus `FakeCloudProvider` for tests.
- `ScaleCloudHosts` action, run on job and host events and every minute by the scheduler. Per template:
  - demand = waiting jobs of linked pools + runners the pools want warm;
  - capacity = free slots on online hosts + slots of hosts still booting;
  - create `ceil((demand − capacity) / max_runners_per_host)` servers, never above `max_hosts` or the global cap, and never below `warm_hosts`.
- Scale-down: a host with no live runner for the idle time (and above `warm_hosts`) is cordoned, then deleted. For providers that bill by the started hour, the host is kept until shortly before its paid hour ends.
- `ReconcileCloudHosts`, every few minutes: compares the provider's servers carrying this instance's label with the database, deletes servers without a host and hosts without a server.
- A waiting job shows "waiting for a cloud host" instead of "no matching pool".

## Start-up time

A fresh server must install Docker and pull the runner image (1.3 GB): several minutes. `php artisan jogger:cloud-image` builds a provider snapshot with Docker, the agent and the runner image already present; with it a server is ready in about a minute. Plain cloud-init on a stock image stays as the fallback.

## Safety

- Servers are labelled `managed-by=jogger` and with the instance id; reconciliation removes orphans both ways.
- Caps: `max_hosts` per template, a global cap, a maximum lifetime per server.
- No enrollment within 5 minutes: delete and retry (limited). Failed deletions retry with backoff and raise an alert.
- Runner containers cannot reach the provider's metadata address; the cloud firewall allows no inbound traffic.
- Provider tokens are project-scoped; every create and delete is in the audit log.
- IPv4 is on by default for cloud hosts (GitHub's endpoints are IPv4-only); IPv6-only needs NAT64 and is an opt-in.

## UI and API

- Settings → Cloud providers (administrators): accounts and templates, with a "test credentials" action.
- Pool form: "Scale with cloud hosts" (choose templates).
- Hosts page: "ephemeral" badge, template, age, state. Insights: host-hours and estimated cost per template.
- API: read-only `cloud-hosts` listing in v1; management stays in the UI at first.

## Provider shortlist (to validate)

Billing granularity matters: with hourly billing a 3-minute job costs a full hour unless the server is reused.

| Provider | Why | Billing | Integration effort |
|---|---|---|---|
| Hetzner Cloud | Decided. Cheap, EU, simple token API, snapshots, cloud-init | by the started hour | low |
| Scaleway | EU (France), token API, cloud-init, snapshots | hourly — to confirm | low |
| DigitalOcean | Simplest API of all, wide use | per second (since 2026) — to confirm | low |
| AWS EC2 | Most common overall; spot instances | per second | high (IAM, VPC, AMI per region) |
| OVHcloud Public Cloud | EU, common in France | hourly | medium–high (OpenStack API; one driver would also cover other OpenStack clouds) |
| Google Cloud, Azure | Common in companies | per second | high |
| Vultr, Linode (Akamai), UpCloud | Similar to Hetzner | hourly | low each, lower demand |

Suggested order after Hetzner: Scaleway, DigitalOcean, AWS EC2; OVHcloud through a generic OpenStack driver if wanted. Prices and billing rules must be re-checked against each provider's documentation when its driver is written.

## Work breakdown

| Step | Content | Effort |
|---|---|---|
| C1 | Tables, models, encryption, `CloudProvider` interface, fake driver | 4 h |
| C2 | Hetzner driver, HTTP-faked tests | 4 h |
| C3 | Scale-up: scaler, boot timeout, re-scheduling when a host comes online, warm servers | 6 h |
| C4 | Scale-down, drain, delete, hourly-billing rule, reconciliation, caps | 5 h |
| C5 | UI, API listing, audit, alerts, insights | 6 h |
| C6 | Snapshot build command, cloud-init script, metadata block | 3 h |
| C7 | Documentation and a real run against a Hetzner project | 3 h |
| C8+ | One step per additional validated provider | 3–8 h each |
