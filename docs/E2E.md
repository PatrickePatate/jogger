# End-to-end run against real GitHub

This is the manual procedure for the first real job, and the place to record what it showed.

> **Status: not yet performed.** Everything up to here is verified with a fake GitHub gateway and, on the agent side,
> a real Docker engine with stand-in images. Running a real job needs a GitHub account, a GitHub App and a public
> URL, which only a person can provide. Fill in the "Results" section when you run it.

## What you need

- A GitHub repository you own (private is safest) with Actions enabled.
- A machine with Docker for the agent (it can be the same machine as the server).
- A public HTTPS URL that reaches your Jogger server, for webhooks. For a local server use a tunnel, for example
  `cloudflared tunnel --url http://localhost:8000` or `ngrok http 8000`.
- The `gh` CLI, signed in, if you want to use the script.

## Procedure

1. **Start the server** with `APP_URL` set to the public URL: database, Redis, `php artisan serve`, `php artisan
   reverb:start`, `php artisan queue:work` (or Horizon) and `php artisan schedule:work`. The scheduler matters:
   it runs the reaper, the pool reconcile and the GitHub reconcile.
2. **Finish setup** at `/setup`, then open **GitHub** and create the GitHub App from the manifest. Confirm on
   GitHub that the app has the permissions *Actions: read*, *Administration: read & write* (repository) and/or
   *Self-hosted runners: read & write* (organization), and is subscribed to the *Workflow job* event.
3. **Install the app** on the test repository. It should appear under **Installations** with its repositories.
4. **Build the images** (until they are published):
   ```bash
   docker build -f docker/runner/Dockerfile -t jogger-runner:dev docker/runner
   ```
   ```bash
   docker build -f docker/agent/Dockerfile -t jogger-agent:dev .
   ```
5. **Add a host**: Hosts → Add host, and run the printed command on the Docker machine (replace the image name with
   `jogger-agent:dev`). The host should turn *Online* within a few seconds.
6. **Create a pool**: scope = the test repository, labels `self-hosted, linux, x64` (or `arm64`), image
   `jogger-runner:dev`, maximum 2.
7. **Add a workflow** to the repository, `.github/workflows/jogger-e2e.yml`:
   ```yaml
   name: jogger-e2e
   on: workflow_dispatch
   jobs:
     hello:
       runs-on: [self-hosted, linux, x64]
       steps:
         - run: echo "hello from $(hostname)" && uname -a && id
   ```
8. **Trigger it** (Actions tab → Run workflow), or run the script:
   ```bash
   JOGGER_URL=https://your-url JOGGER_TOKEN=jog_xxx REPO=owner/name tests/Script/e2e.sh
   ```

## What to check

| Check | Expected |
|---|---|
| Job on GitHub | completes green |
| Jogger job | `queued` → `in_progress` → `completed`, linked to a runner and pool |
| Runner | `provisioning` → `idle` → `busy` → `terminating` → `gone`, reason `completed` |
| Container | `docker ps -a --filter label=jogger.runner` is empty within 30 s of completion |
| GitHub runner | gone from the repository's runner list within 30 s |
| Logs | `GET /api/v1/jobs/{id}/logs` returns the runner output |
| Latency | queued → running; target median under 15 s with the image already pulled |

## Things most likely to need fixing on the first run

These are the parts that have never met the real thing; check them first if the job does not start.

- **GitHub App manifest**: permission and event names in `GithubAppManifest`.
- **JIT config call**: the labels sent, and the runner group id (default 1).
- **`workflow_job` payload**: `runner_name` must equal the name Jogger generated (`jogger-…`).
- **Runner image**: GitHub rejects runner versions that are too old; rebuild with the current `RUNNER_VERSION`.
- **Reverb from the agent**: the agent falls back to polling every 15 s, which alone can exceed the latency target.
- **Container hardening**: `CapDrop ALL` and `no-new-privileges` may break a tool the runner needs.

## Results

| Date | Runner version | Host | Queued → running (median of n) | Outcome | Notes |
|---|---|---|---|---|---|
| | | | | | |

## Fixes made because of this run

_None yet._
