# Jogger default runner image: Ubuntu LTS + the official GitHub Actions runner, started with a single-use JIT config.
# Build from the repository root:  docker build -f docker/runner/Dockerfile docker/runner
FROM ubuntu:24.04

ARG RUNNER_VERSION=2.328.0
ARG TARGETARCH

ENV DEBIAN_FRONTEND=noninteractive \
    RUNNER_HOME=/home/runner \
    # Jogger replaces the whole container per job, so the runner must never update itself in place.
    RUNNER_ALLOW_RUNASROOT=0 \
    DOTNET_SYSTEM_GLOBALIZATION_INVARIANT=0

# Languages (Node, Python, Go, …) are deliberately absent: workflows install them with the setup-* actions.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      ca-certificates curl git git-lfs jq unzip zip tar xz-utils gnupg openssh-client build-essential sudo tini \
 && install -m 0755 -d /etc/apt/keyrings \
 && curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc \
 && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "$VERSION_CODENAME") stable" > /etc/apt/sources.list.d/docker.list \
 && apt-get update \
 && apt-get install -y --no-install-recommends docker-ce-cli docker-buildx-plugin docker-compose-plugin \
 && rm -rf /var/lib/apt/lists/*

RUN useradd --create-home --uid 1001 --shell /bin/bash runner \
 && mkdir -p /opt/hostedtoolcache && chown runner:runner /opt/hostedtoolcache

WORKDIR /home/runner
RUN case "${TARGETARCH:-$(dpkg --print-architecture)}" in \
      amd64) arch=x64 ;; \
      arm64) arch=arm64 ;; \
      *) echo "unsupported architecture" >&2; exit 1 ;; \
    esac \
 && curl -fsSL -o runner.tar.gz "https://github.com/actions/runner/releases/download/v${RUNNER_VERSION}/actions-runner-linux-${arch}-${RUNNER_VERSION}.tar.gz" \
 && tar xzf runner.tar.gz && rm runner.tar.gz \
 && ./bin/installdependencies.sh \
 && rm -rf /var/lib/apt/lists/* \
 && chown -R runner:runner /home/runner

COPY --chmod=0755 entrypoint.sh /usr/local/bin/jogger-runner-entrypoint

ENV AGENT_TOOLSDIRECTORY=/opt/hostedtoolcache \
    RUNNER_VERSION=${RUNNER_VERSION}

USER runner
ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/jogger-runner-entrypoint"]
