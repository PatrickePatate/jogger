VERSION ?= 0.1.0-dev
LDFLAGS := -s -w -X github.com/PatrickePatate/jogger/agent/internal/telemetry.Version=$(VERSION)

.PHONY: build build-all test vet lint clean
build:
	CGO_ENABLED=0 go build -trimpath -ldflags "$(LDFLAGS)" -o bin/jogger-agent ./cmd/jogger-agent

# Release binaries for the platforms hosts run on.
build-all:
	for arch in amd64 arm64; do \
		CGO_ENABLED=0 GOOS=linux GOARCH=$$arch go build -trimpath -ldflags "$(LDFLAGS)" -o bin/jogger-agent-linux-$$arch ./cmd/jogger-agent || exit 1; \
	done

lint:
	golangci-lint run ./...

vet:
	go vet ./...

test: vet
	go test ./...

clean:
	rm -rf bin
