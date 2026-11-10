# Jogger runner image

The default image for runner pools: Ubuntu 24.04 with the official [`actions/runner`](https://github.com/actions/runner),
git, git-lfs, curl, jq, zip/unzip, build-essential and the Docker CLI (with buildx and compose). Languages are not
preinstalled; workflows get them from the `setup-*` actions, which cache into `/opt/hostedtoolcache`.

| | |
|---|---|
| User | `runner` (uid 1001), never root |
| Entrypoint | `run.sh --jitconfig "$JIT_CONFIG"` under `tini` |
| Size | about 1.3 GB uncompressed (amd64, runner 2.328.0) |
| Architectures | `linux/amd64`, `linux/arm64` |

## How it starts

The Jogger agent starts one container per job and passes a single-use credential in `JIT_CONFIG`. The entrypoint
removes that variable from the environment before starting the runner, so job steps do not inherit it. Started
without `JIT_CONFIG`, the container prints an explanation and exits with status 64.

## Building

```bash
docker build -f docker/runner/Dockerfile --build-arg RUNNER_VERSION=2.328.0 -t jogger-runner:dev docker/runner
```

`.github/workflows/runner-image.yml` rebuilds the image every week with the latest `actions/runner` release and
pushes `ghcr.io/<owner>/jogger-runner:<runner version>` and `:latest`. Keep it running: GitHub stops accepting
runners that are too many releases behind.

## Using your own image

Any image works as long as it starts the runner with the JIT config from `JIT_CONFIG` and runs as a non-root user.
Start from this one (`FROM ghcr.io/<owner>/jogger-runner`) to add tools.

The Docker CLI is only useful in pools with "Docker in Docker" enabled; Jogger then points `DOCKER_HOST` at a
private daemon for that job. The host's Docker socket is never mounted.
