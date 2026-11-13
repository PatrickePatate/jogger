package commands

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"log/slog"
	"os"
	"time"

	"github.com/PatrickePatate/jogger/agent/internal/docker"
	"github.com/PatrickePatate/jogger/agent/internal/events"
)

// StopGrace is how long a runner gets to finish after SIGTERM before it is killed.
const StopGrace = 30 * time.Second

// RegisterRunner wires runner.start and runner.stop to the Docker runner.
// onStarted runs in the background for each newly created runner container (the log shipper hooks in here).
func RegisterRunner(d *Dispatcher, r *docker.Runner, emit func(events.Event), onStarted func(ctx context.Context, runnerID, containerID string)) {
	d.Register("runner.start", func(ctx context.Context, cmd Command) (map[string]any, error) {
		var spec docker.Spec
		if err := decode(cmd.Payload, &spec); err != nil {
			return nil, fmt.Errorf("invalid runner.start payload: %w", err)
		}
		started, err := r.Start(ctx, spec)
		if err != nil {
			return nil, err
		}
		if !started.Existing {
			emit(events.Event{Type: events.ContainerStarted, RunnerID: spec.RunnerID, Data: map[string]any{"container_id": started.ContainerID}})
			if onStarted != nil {
				// The command's own context ends with the agent, which is also when shipping should stop.
				go onStarted(ctx, spec.RunnerID, started.ContainerID)
			}
		}

		return map[string]any{"container_id": started.ContainerID, "existing": started.Existing}, nil
	})

	// image.pull warms a host's image cache ahead of the first runner; runner.start pulls on demand anyway.
	d.Register("image.pull", func(ctx context.Context, cmd Command) (map[string]any, error) {
		var p struct {
			Image    string               `json:"image"`
			Registry *docker.RegistryAuth `json:"registry_auth"`
		}
		if err := decode(cmd.Payload, &p); err != nil || p.Image == "" {
			return nil, errors.New("invalid image.pull payload: image is required")
		}
		if err := r.Docker.Pull(ctx, p.Image, p.Registry); err != nil {
			return nil, err
		}

		return map[string]any{"image": p.Image}, nil
	})

	d.Register("host.prune", func(ctx context.Context, cmd Command) (map[string]any, error) {
		var p struct {
			MaxBuildCacheGB float64 `json:"max_build_cache_gb"`
		}
		_ = decode(cmd.Payload, &p) // an empty payload is a plain prune
		res, err := r.Prune(ctx)
		if err != nil {
			return nil, err
		}
		freed, err := r.PruneBuildCache(ctx, p.MaxBuildCacheGB)
		if err != nil {
			return nil, err
		}

		return map[string]any{"images_deleted": res.ImagesDeleted, "bytes_freed": res.BytesFreed + freed, "build_cache_bytes_freed": freed}, nil
	})

	// agent.update replaces this agent's own container with one running the new image. The swap happens after the
	// ack so the server sees the command succeed; if preparing fails the running agent is untouched.
	d.Register("agent.update", func(ctx context.Context, cmd Command) (map[string]any, error) {
		var p struct {
			Image    string               `json:"image"`
			Version  string               `json:"version"`
			Registry *docker.RegistryAuth `json:"registry_auth"`
		}
		if err := decode(cmd.Payload, &p); err != nil || p.Image == "" {
			return nil, errors.New("invalid agent.update payload: image is required")
		}
		self, err := os.Hostname()
		if err != nil {
			return nil, err
		}
		rep, err := r.Docker.PrepareReplacement(ctx, self, p.Image, p.Registry)
		if err != nil {
			return nil, err
		}
		go func() {
			time.Sleep(3 * time.Second)
			if err := rep.Finish(context.Background()); err != nil {
				slog.Error("agent update failed; the current agent keeps running", "error", err)
			}
		}()

		return map[string]any{"image": p.Image, "version": p.Version}, nil
	})

	d.Register("runner.stop", func(ctx context.Context, cmd Command) (map[string]any, error) {
		var p struct {
			RunnerID string `json:"runner_id"`
		}
		if err := decode(cmd.Payload, &p); err != nil || p.RunnerID == "" {
			return nil, errors.New("invalid runner.stop payload: runner_id is required")
		}
		stopped, err := r.Stop(ctx, p.RunnerID, StopGrace)
		if err != nil {
			return nil, err
		}

		return map[string]any{"stopped": stopped}, nil
	})
}

func decode(payload map[string]any, into any) error {
	raw, err := json.Marshal(payload)
	if err != nil {
		return err
	}

	return json.Unmarshal(raw, into)
}
