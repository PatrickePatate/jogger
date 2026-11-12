# syntax=docker/dockerfile:1
# Build from the repository root:  docker build -f docker/agent/Dockerfile .
FROM golang:1.25-alpine AS build
ARG VERSION=dev
WORKDIR /src
COPY agent/go.mod agent/go.sum ./
RUN go mod download
COPY agent/ ./
RUN CGO_ENABLED=0 go build -trimpath \
    -ldflags "-s -w -X github.com/PatrickePatate/jogger/agent/internal/telemetry.Version=${VERSION}" \
    -o /out/jogger-agent ./cmd/jogger-agent
RUN mkdir -p /out/data

# Distroless static: no shell, no package manager, runs as an unprivileged user. The agent needs the Docker socket
# (mounted by the operator) and a writable /var/lib/jogger-agent for its credential.
FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build --chown=nonroot:nonroot /out/jogger-agent /usr/local/bin/jogger-agent
# Same path as the agent's default and the documented volume mount, owned by the unprivileged user.
COPY --from=build --chown=nonroot:nonroot /out/data /var/lib/jogger-agent
VOLUME /var/lib/jogger-agent
USER nonroot:nonroot
ENTRYPOINT ["/usr/local/bin/jogger-agent"]
