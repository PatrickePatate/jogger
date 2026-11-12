// jogger-agent connects a machine to a Jogger control plane and reports its capacity.
package main

import (
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/PatrickePatate/jogger/agent/internal/commands"
	"github.com/PatrickePatate/jogger/agent/internal/config"
	"github.com/PatrickePatate/jogger/agent/internal/docker"
	"github.com/PatrickePatate/jogger/agent/internal/enroll"
	"github.com/PatrickePatate/jogger/agent/internal/events"
	"github.com/PatrickePatate/jogger/agent/internal/logs"
	"github.com/PatrickePatate/jogger/agent/internal/telemetry"
	"github.com/PatrickePatate/jogger/agent/internal/transport"
	"github.com/PatrickePatate/jogger/agent/internal/update"
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
	updater := &update.Checker{Current: telemetry.Version}
	collector.UpdateAvailable = updater.Available
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
	updater.Fetch = update.FetchHTTP(client)

	dispatcher := commands.NewDispatcher(commands.HTTPAPI{Client: client}, 15*time.Second, 4)
	runner := &docker.Runner{Docker: docker.New(cfg.DockerSocket), HostID: cred.HostID, DindImage: cfg.DindImage}
	emitter := events.NewEmitter(func(ctx context.Context, e events.Event) error {
		return client.Do(ctx, "POST", "/agent/v1/events", e, nil)
	}, 256)
	commands.RegisterRunner(dispatcher, runner, emitter.Emit, func(ctx context.Context, runnerID, containerID string) {
		logs.Follow(ctx, runner.Docker, containerID, runnerID, func(ctx context.Context, b logs.Batch) error {
			return client.Do(ctx, "POST", "/agent/v1/runners/"+runnerID+"/logs", b, nil)
		})
	})
	go emitter.Run(ctx)
	go runner.Watch(ctx, func(x docker.Exit) {
		emitter.Emit(events.Event{Type: events.ContainerExited, RunnerID: x.RunnerID, Data: map[string]any{"exit_code": x.ExitCode, "oom": x.OOM}})
	})
	go dispatcher.Run(ctx)
	go housekeeping(ctx, runner, client, updater, cfg.AutoUpdate, stop)
	if cred.Reverb != nil {
		pusher := &transport.Pusher{
			URL:     transport.ReverbURL(cred.Reverb.Scheme, cred.Reverb.Host, cred.Reverb.Port, cred.Reverb.Key),
			Channel: "private-host." + cred.HostID,
			Authorize: func(ctx context.Context, socketID, channel string) (string, error) {
				var resp struct {
					Auth string `json:"auth"`
				}
				err := client.Do(ctx, "POST", "/agent/v1/broadcasting/auth", map[string]string{"socket_id": socketID, "channel_name": channel}, &resp)
				return resp.Auth, err
			},
			OnEvent: func(event, _ string) {
				if event == "command.available" {
					dispatcher.Trigger()
				}
			},
			OnConnect: dispatcher.Trigger,
		}
		go pusher.Run(ctx)
	}

	return telemetry.Loop(ctx, cfg.HeartbeatInterval, collector.Collect,
		func(ctx context.Context, f telemetry.Facts) error {
			return client.Do(ctx, "POST", "/agent/v1/heartbeat", f, nil)
		},
		backoff.Delay, transport.Retryable, transport.Sleep,
		func(err error) { slog.Warn("heartbeat failed, retrying with backoff", "error", err) },
	)
}

// housekeeping periodically removes orphaned containers and checks for a newer agent.
func housekeeping(ctx context.Context, runner *docker.Runner, client *transport.Client, updater *update.Checker, autoUpdate bool, stop context.CancelFunc) {
	for {
		if info, err := updater.Check(ctx); err != nil {
			slog.Debug("version check failed", "error", err)
		} else if v := updater.Available(); v != "" {
			slog.Info("a newer agent is available", "current", telemetry.Version, "latest", v)
			if autoUpdate {
				if exe, err := os.Executable(); err != nil {
					slog.Warn("cannot locate the agent binary, not updating", "error", err)
				} else if err := update.Apply(ctx, http.DefaultClient, info, exe); err != nil {
					slog.Warn("self-update failed", "error", err)
				} else {
					slog.Info("agent updated, exiting so the supervisor restarts it", "version", v)
					stop()
					return
				}
			}
		}

		var resp struct {
			Runners []string `json:"runners"`
		}
		switch err := client.Do(ctx, "GET", "/agent/v1/runners", nil, &resp); {
		case err != nil:
			// Without the server's list nothing can be called an orphan, so do nothing (the endpoint arrives later).
			slog.Debug("runner list unavailable, skipping orphan cleanup", "error", err)
		default:
			known := docker.Known{}
			for _, id := range resp.Runners {
				known[id] = true
			}
			if removed, err := runner.RemoveOrphans(ctx, known, 10*time.Minute, time.Now()); err != nil {
				slog.Warn("orphan cleanup failed", "error", err)
			} else if len(removed) > 0 {
				slog.Info("removed orphaned runners", "runners", removed)
			}
		}

		select {
		case <-ctx.Done():
			return
		case <-time.After(5 * time.Minute):
		}
	}
}
