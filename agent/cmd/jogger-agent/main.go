// jogger-agent connects a machine to a Jogger control plane and reports its capacity.
package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"github.com/PatrickePatate/jogger/agent/internal/config"
	"github.com/PatrickePatate/jogger/agent/internal/enroll"
	"github.com/PatrickePatate/jogger/agent/internal/telemetry"
	"github.com/PatrickePatate/jogger/agent/internal/transport"
)

func main() {
	if err := run(); err != nil && !errors.Is(err, context.Canceled) {
		slog.Error("jogger-agent stopped", "error", err)
		os.Exit(1)
	}
}

func run() error {
	if len(os.Args) > 1 && (os.Args[1] == "--version" || os.Args[1] == "version") {
		fmt.Println("jogger-agent", telemetry.Version)
		return nil
	}

	cfg, err := config.Load(os.Args[1:], os.Getenv, os.ReadFile, os.Hostname)
	if err != nil {
		return err
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	collector := telemetry.NewCollector(cfg.DataDir, cfg.DockerSocket)
	newClient := func(credential string) *transport.Client {
		return transport.New(cfg.Server, credential, telemetry.Version)
	}
	backoff := transport.NewBackoff()

	var cred enroll.Credential
	err = transport.Retry(ctx, backoff, 0, transport.Sleep, func(ctx context.Context) error {
		var enrolled bool
		var err error
		cred, enrolled, err = enroll.Ensure(ctx, cfg.Server, cfg.Token, cfg.Hostname, cfg.DataDir, collector.Collect(ctx), newClient)
		if enrolled {
			slog.Info("enrolled", "host_id", cred.HostID, "name", cred.Name)
		}
		if err != nil && transport.Retryable(err) {
			slog.Warn("could not reach the server, retrying", "error", err)
		}

		return err
	})
	if err != nil {
		return err
	}

	slog.Info("agent started", "server", cfg.Server, "host_id", cred.HostID, "version", telemetry.Version, "interval", cfg.HeartbeatInterval)
	client := newClient(cred.Credential)

	return telemetry.Loop(ctx, cfg.HeartbeatInterval, collector.Collect,
		func(ctx context.Context, f telemetry.Facts) error {
			return client.Do(ctx, "POST", "/agent/v1/heartbeat", f, nil)
		},
		backoff.Delay, transport.Retryable, transport.Sleep,
		func(err error) { slog.Warn("heartbeat failed, retrying with backoff", "error", err) },
	)
}
