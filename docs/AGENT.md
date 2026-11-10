# Hosts and the agent

A **host** is a machine that runs your jobs. Each host runs the **Jogger agent**, a small program that connects out to your Jogger server, starts and stops runner containers through the local Docker Engine, and ships their logs. The server never connects to the host, so hosts can sit behind NAT or a firewall as long as they can reach the server over HTTPS.

## Requirements

- Linux (amd64 or arm64) with Docker Engine 24 or newer.
- Outbound HTTPS to your Jogger server and to GitHub (runners talk to GitHub directly).
- Enough disk for runner images (the default image is about 1.3 GB) and build caches.

## Adding a host

1. In Jogger, open **Hosts** and select **Add host**. Jogger shows a command containing a one-time enrollment token. The token is valid for 60 minutes and can be used once.
2. Run that command on the host. It looks like this:

   ```bash
   docker run -d --name jogger-agent --restart unless-stopped \
     -v /var/run/docker.sock:/var/run/docker.sock \
     -v jogger-agent-data:/var/lib/jogger-agent \
     -e JOGGER_SERVER=https://ci.example.com \
     -e JOGGER_ENROLL_TOKEN=jhe_... \
     ghcr.io/jogger/jogger-agent:latest
   ```

3. The host appears on the Hosts page as **Online** within a few seconds.

On first start the agent exchanges the token for its own long-lived credential and stores it in the `jogger-agent-data` volume. Keep that volume: with it the agent reconnects after a restart without a new token. The enrollment token is useless afterwards.

The agent container needs access to the Docker socket, which is equivalent to root on that host. Treat a host as dedicated to running jobs. If the container cannot open the socket, add `--group-add <gid of /var/run/docker.sock>`.

## Configuration

Every setting can be given as a flag, an environment variable, or a key in a config file (`--config` / `JOGGER_CONFIG`). Flags win over environment variables, which win over the file.

| Flag | Environment variable | Meaning |
|---|---|---|
| `--server` | `JOGGER_SERVER` | URL of the Jogger server. Required. |
| `--token` | `JOGGER_ENROLL_TOKEN` | One-time enrollment token. Only needed the first time. |
| `--data-dir` | `JOGGER_DATA_DIR` | Where the credential is stored. Default `/var/lib/jogger-agent`. |
| `--name` | `JOGGER_HOSTNAME` | Name shown in Jogger. Default: the machine's hostname. |
| `--docker-socket` | `JOGGER_DOCKER_SOCKET` | Docker socket path. Default `/var/run/docker.sock`. |
| `--heartbeat-interval` | | How often the agent reports in. |
| `--auto-update` | | Install newer agent versions advertised by the server. |

## Host states and actions

| State | Meaning |
|---|---|
| Pending | Enrolled, no heartbeat received yet. |
| Online | Reporting in; new runners can start here. |
| Offline | Heartbeats stopped. Its runners are treated as lost and their jobs are picked up elsewhere when possible. |
| Cordoned | Reachable, but no new runners start here. Running jobs continue. |
| Draining | Like cordoned, on the way to empty; use it before maintenance. |
| Revoked | Its credential no longer works. Permanent. |

On the Hosts page an administrator can **Cordon**, **Drain**, **Resume** or **Revoke** a host. Revoke a host as soon as it is decommissioned or you suspect it is compromised. To bring a revoked machine back, enroll it again with a new token.

Each host card shows CPU, memory and free disk as last reported, the agent version, and the runners currently on it.

## What the agent does on the host

- Creates one container per runner, on a private network of its own, as a non-root user, with all capabilities dropped and the pool's CPU and memory limits. The host's Docker socket is never given to a job.
- For pools with "Docker inside jobs", starts a rootless Docker-in-Docker sidecar next to the runner (see [SECURITY.md](SECURITY.md) for the trade-off).
- Removes containers, networks and leftover volumes when a runner ends, and cleans up anything left behind by a crash when it restarts.
- Once a day, on request of the server, removes dangling images and trims the Docker build cache to the configured size.

## Running jobs on the Jogger server itself

Possible for a trial with the `local-host` compose profile; see [INSTALL.md](INSTALL.md). Not recommended otherwise: a job that escapes its container would be on the machine that holds your database and keys.

## Updating the agent

Pull the new image and recreate the container with the same volume and environment (without the enrollment token):

```bash
docker pull ghcr.io/jogger/jogger-agent:latest
```

```bash
docker rm -f jogger-agent
```

Then run the `docker run` command again without the `JOGGER_ENROLL_TOKEN` line.
