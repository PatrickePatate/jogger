VERSION ?= 0.1.0-dev
LDFLAGS := -s -w -X github.com/PatrickePatate/jogger/agent/internal/telemetry.Version=$(VERSION)

.PHONY: build test vet clean
build:
	CGO_ENABLED=0 go build -trimpath -ldflags "$(LDFLAGS)" -o bin/jogger-agent ./cmd/jogger-agent

vet:
	go vet ./...

test: vet
	go test ./...

clean:
	rm -rf bin
