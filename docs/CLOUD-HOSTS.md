# Cloud hosts

> Status: **implemented for Hetzner Cloud (steps C1–C6), tested against a simulated provider only.** No real server has been created yet. Scaleway and DigitalOcean (C8, C9) are implemented and tested against faked HTTP only; AWS EC2 (C10) is implemented and tested against faked HTTP only; OpenStack (C11) is not implemented. Decisions marked "decided" were made by the project owner on 2026-11-12.

A pool can be backed by a cloud template. When jobs wait and no host has a free slot, Jogger creates a server at a cloud provider, the agent on it enrolls, the jobs run, and Jogger deletes the server once it has been idle.

## Flow

1. A job is queued and matches a pool, but no online host has a free slot.
2. Jogger creates a single-use enrollment token (short lifetime, preset host name) and asks the provider for a server, with a cloud-init script that starts `jogger-agent` with that token.
3. The server boots and the agent enrolls. When the host comes online Jogger schedules the pool's waiting jobs again; from here it is an ordinary host.
4. When the host has had no runner for the template's idle time, Jogger cordons it, deletes the server through the provider and removes the host.

## Decided

- **Jobs per server** is a template setting (`max_runners_per_host`). 1 means one server per job, which is the strongest isolation.
- **Warm servers** is a template setting (`warm_hosts`, default 0). 0 is scale-from-zero: no server exists while nothing is queued.
- **Providers**: Hetzner Cloud first, then Scaleway, DigitalOcean and AWS EC2, in that order. A generic OpenStack driver is planned after those (it covers OVHcloud Public Cloud and other OpenStack clouds).

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

## Providers

Billing granularity matters: with hourly billing a 3-minute job costs a full hour unless the server is reused. Prices and billing rules below are from memory and must be re-checked against each provider's documentation when its driver is written.

| Order | Provider | Billing | Notes for the driver |
|---|---|---|---|
| 1 | Hetzner Cloud | by the started hour | Token API; labels; snapshots; cloud-init user data |
| 2 | Scaleway | hourly — to confirm | Token (secret key) + project id; zones; tags instead of labels; cloud-init through user data |
| 3 | DigitalOcean | per second — to confirm | Token API; tags; snapshots; user data. The simplest of the four |
| 4 | AWS EC2 | per second | Access key + region; tags; AMI per region; needs a subnet and security group, so the template has more fields. Optional spot instances with on-demand fallback |
| 5 | OpenStack (generic) | depends on the cloud | See below |

Not planned: Google Cloud, Azure, Vultr, Linode, UpCloud. The interface allows adding them later.

### OpenStack driver

One driver for any OpenStack cloud, OVHcloud Public Cloud first.

- **Credentials**: Keystone v3 auth URL, region, project id, and either an application credential (id + secret, preferred: scoped and revocable) or user name, password and domain.
- **Service discovery**: endpoints for compute (Nova), image (Glance) and network (Neutron) are read from the Keystone token's catalog; nothing is hard-coded per cloud.
- **Template fields**: flavor, image (or snapshot) id, network id(s), security group, optional key pair, availability zone.
- **Operations**: create server with `user_data` (cloud-init) and `metadata` (`managed-by`, instance id); delete; get; list filtered by metadata for reconciliation.
- **Differences to handle**: tokens expire and must be renewed; quotas return a specific error that the scaler reports as "quota reached"; some clouds need a floating IP for IPv4 while OVHcloud's public network gives one directly, so floating IP allocation is an optional template setting; billing rules are per cloud, so the template carries its own "billed by the hour" flag.
- **Tests**: HTTP-faked Keystone and Nova responses; a real run against an OVHcloud project before calling it supported.

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
| C8 | Scaleway driver | 3 h |
| C9 | DigitalOcean driver | 3 h |
| C10 | AWS EC2 driver (template fields for subnet and security group, optional spot) | 8 h |
| C11 | OpenStack driver, verified on OVHcloud | 8 h |

## Using it (Hetzner)

1. In the Hetzner Cloud console create a project and an API token with read and write access.
2. In Jogger, open **Cloud** in the administration menu (`/admin/cloud`). Add a provider: driver `hetzner`, paste the token. Jogger checks the token before saving and never shows it again.
3. Add a template: server type (for example `cx22`), location (for example `nbg1`), image (`ubuntu-24.04`, or a snapshot id), jobs per server, warm servers, maximum servers, idle time, and the pools that may use it.
4. Queue a job for one of those pools. With no free host, Jogger creates a server; the job shows "Waiting for a cloud server" until its agent enrolls.

Settings: `JOGGER_CLOUD_MAX_HOSTS` (global cap, default 10), `JOGGER_AGENT_IMAGE` (agent image started on new servers), `JOGGER_INSTANCE_ID` (label written on servers; defaults to a value derived from `APP_KEY`). Two installations sharing one cloud project must have different instance ids, or each would delete the other's servers as orphans.

Scheduler: `jogger:scale-cloud-hosts` every minute (boot timeouts, scale-up, scale-down), `jogger:reconcile-cloud-hosts` every five minutes (orphans, lost servers, retried deletions).

### Scaleway and DigitalOcean

- **Scaleway**: credentials are a secret key and a project id. The template's location is a zone (`fr-par-1`), the server type a commercial type (`DEV1-M`), the image a label (`ubuntu_jammy`) or an image UUID. Server ids are stored as `zone/uuid`. Orphan listing only looks at the zones used by the provider's templates.
- **DigitalOcean**: credentials are an API token. The location is a region (`fra1`), the server type a size slug (`s-2vcpu-4gb`), the image a slug or a snapshot id. A droplet always has a public IPv4, so the template's IPv4 switch is ignored. Template options accepted: `ssh_keys`, `vpc_uuid`, `volumes`.
- Both store Jogger's labels as `key:value` tags. Neither was tried against a real account.

### AWS EC2

Credentials: an IAM access key id and its secret (permissions: `ec2:RunInstances`, `DescribeInstances`, `TerminateInstances`, `CreateTags`). Requests are signed with Signature V4 by the driver itself; no SDK was added. The location is a region (`eu-west-3`), the server type an instance type (`c6i.xlarge`), the image an AMI id (`ami-…`); AMIs are per region. Server ids are `region/instance-id`, and orphan listing looks at the regions used by the provider's templates.

Provider options (JSON in the template form): `subnet_id`, `security_group_ids` (list), `key_name`, `iam_instance_profile`, `root_volume_gb`, `spot` (`true` for a one-time spot request). Without `subnet_id` the region's default VPC is used. Instances terminate when shut down from inside. EC2 bills per second (60 s minimum) for Linux, so turn "billed by the started hour" off on these templates. A terminated instance stays visible for about an hour at AWS; Jogger treats it as gone.

### Activating and deactivating a provider

Administration → Cloud has an Activate/Deactivate button per provider. An inactive provider gets no new servers and is skipped by the orphan sweep; servers it already has are still deleted when idle.

## Not done or not verified

- **No run against a real provider.** The Hetzner driver is tested with faked HTTP responses, the cloud-init script only with a syntax check. The first real server is the real test (step C7).
- No command to build a snapshot image yet: a stock image installs Docker and pulls the runner image at boot, which takes several minutes. Raise `cloud.boot_timeout_seconds` (300) if servers are deleted before they enroll.
- The provider call happens inside a database transaction; if the commit fails after the server was created, the server is an orphan until reconciliation removes it (10 minutes at the earliest).
- No alert rule and no cost page yet; cloud events are in the audit log only.
- A cloud server has `sudo` and Docker-in-Docker constraints like any host; the rootless sidecar should work on a real VM but was not tried.
