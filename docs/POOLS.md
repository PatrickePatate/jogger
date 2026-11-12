# Pools, runners and secrets

A **pool** is a set of identical, single-use runners for one repository or one organization. When GitHub queues a job whose labels match a pool, Jogger starts a fresh runner container on one of your hosts, the runner takes exactly one job, and is then destroyed.

## Creating a pool

Open **Pools → New pool**. You need the Operator role on the installation.

| Field | Meaning |
|---|---|
| Installation | The GitHub account or organization the runners register with. |
| Scope | **Repository**: runners serve one repository. **Organization**: runners serve every repository of the organization that may use them. |
| Target | The repository (`owner/name`) or the organization login. |
| Name | For you; shown in Jogger. |
| Labels | What workflows put in `runs-on`. A job runs here when all of its labels are in this list. Default `self-hosted, linux, x64`. |
| Image | The runner container image. The default image has the GitHub runner and common tools. |
| CPU limit / Memory limit | Per runner. Empty means no limit. With a memory limit, a job that exceeds it is killed. |
| Keep warm (`min_idle`) | Runners kept started and waiting, so jobs begin without the container start delay. |
| Maximum runners | Upper bound of runners alive at once for this pool. Further jobs wait in GitHub's queue. |
| Idle time (`idle_ttl_seconds`) | How long an unused warm runner lives before it is replaced. Default 15 minutes. |
| Docker inside jobs | **None**, or **Docker-in-Docker** when jobs need `docker build` or service containers. See the security note below. |
| Only on hosts labelled | Restrict the pool to hosts carrying all of these host labels. Empty means any host. |
| Keep the tool cache between jobs | Keeps toolchains downloaded by `actions/setup-*` in a volume on each host, shared by this pool's runners only. |
| Allow sudo in jobs | Lets job steps use `sudo`, which actions that install packages need (for example `shivammathur/setup-php`). The runner then keeps Docker's default capabilities and jobs can become root inside their container. Leave it off for pools that run untrusted code. |

For a **public repository** Jogger asks you to confirm: anyone who can open a pull request can run code on your hosts. Read [SECURITY.md](SECURITY.md) first.

A pool can be **paused**: running jobs finish, no new runner starts.

## How a job gets a runner

1. GitHub sends the "job queued" event.
2. Jogger looks for an active pool of the job's repository (or its organization) whose labels cover the job's labels. If several match, the most specific one wins.
3. If the pool is below its maximum, Jogger picks a host (online, matching host labels, preferring hosts that already have the image) and asks its agent to start a runner.
4. The runner registers with GitHub using a single-use configuration, takes the job, and exits.

Jogger also compares its view with GitHub every minute, so a missed event delays a job by at most about a minute.

### When a job stays queued

The job page and the Jobs list say why:

| Reason | What to do |
|---|---|
| No matching pool | No active pool has all the job's labels. The Pools page lists these label sets with a **Create pool for these labels** button. |
| Pool paused | Resume the pool. |
| No capacity | The pool is at its maximum, or no suitable host is online. Raise the maximum or add a host. |
| GitHub rate limited | Jogger waits for the limit to reset and retries by itself. |

## Runners

The **Runners** page lists the runners that exist now and those that ended in the last day, with their state: Provisioning, Idle, Busy, Terminating, Gone, or Failed with a reason (for example `oom`, `start_failed`, `provision_timeout`, `host_lost`). Select a runner to see its lifecycle.

An operator can **Terminate** a runner. Terminating a busy runner kills its job and asks for a second confirmation.

## Logs

A job's page shows its log live while it runs and keeps it afterwards for the retention period (30 days by default). Logs can be downloaded as text or gzip. Known secret values are replaced with `***`.

## Secrets and environment variables

**Secrets** (in the sidebar) are values Jogger gives to runner containers. They are separate from GitHub's own secrets, which keep working as usual.

- **Environment variable**: injected into every runner container it applies to, for example a proxy setting.
- **Registry credentials**: a username and password for a container registry (name = the registry host, such as `ghcr.io`), used by hosts to pull a private runner image.

Each secret applies to everything (administrators only), to one installation, or to one pool. When the same name exists at several levels, the narrowest wins: pool, then installation, then everywhere.

Values are encrypted, never shown again after saving, and masked in logs. Saving a name that already exists **rotates** it: runners started afterwards get the new value, runners already started keep the old one until they end.

## Insights and alerts

- **Insights** shows queue depth, queue wait (p50 / p95), runner usage, success rate and runner minutes per repository and pool, over the last hour, day, week or month.
- Administrators receive **alerts** under the bell, and optionally by e-mail or webhook (**Notifications** in the user menu), when a host goes offline or runs low on disk, a pool cannot start runners, GitHub rejects Jogger's credentials, or webhooks fail to process.
