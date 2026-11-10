package commands

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
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

	d.Register("host.prune", func(ctx context.Context, _ Command) (map[string]any, error) {
		res, err := r.Prune(ctx)
		if err != nil {
			return nil, err
		}

		return map[string]any{"images_deleted": res.ImagesDeleted, "bytes_freed": res.BytesFreed}, nil
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
