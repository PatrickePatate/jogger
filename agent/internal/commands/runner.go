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
func RegisterRunner(d *Dispatcher, r *docker.Runner, emit func(events.Event)) {
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
		}

		return map[string]any{"container_id": started.ContainerID, "existing": started.Existing}, nil
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
