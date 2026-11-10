// Package events reports what happened on this host (runner containers starting and exiting) to the control plane.
package events

import (
	"context"
	"log/slog"
	"time"

	"github.com/PatrickePatate/jogger/agent/internal/transport"
)

const (
	ContainerStarted = "container_started"
	ContainerExited  = "container_exited"
)

// Event is one report. Data carries type-specific fields (exit_code, oom, …).
type Event struct {
	Type       string         `json:"type"`
	RunnerID   string         `json:"runner_id"`
	Data       map[string]any `json:"data,omitempty"`
	OccurredAt time.Time      `json:"occurred_at"`
}

// Sender delivers one event (an HTTP POST in production).
type Sender func(ctx context.Context, e Event) error

// Emitter queues events and delivers them in order from one goroutine, retrying transient failures. The queue is
// bounded: a long outage drops the oldest news rather than the agent's memory.
type Emitter struct {
	send     Sender
	queue    chan Event
	backoff  *transport.Backoff
	sleep    func(context.Context, time.Duration) error
	attempts int
}

func NewEmitter(send Sender, queueSize int) *Emitter {
	return &Emitter{send: send, queue: make(chan Event, queueSize), backoff: transport.NewBackoff(), sleep: transport.Sleep, attempts: 8}
}

// Emit never blocks; when the queue is full the oldest event is discarded.
func (e *Emitter) Emit(ev Event) {
	if ev.OccurredAt.IsZero() {
		ev.OccurredAt = time.Now().UTC()
	}
	for {
		select {
		case e.queue <- ev:
			return
		default:
			select {
			case <-e.queue:
				slog.Warn("event queue full, dropping the oldest event")
			default:
			}
		}
	}
}

// Run delivers events until ctx ends.
func (e *Emitter) Run(ctx context.Context) {
	for {
		select {
		case <-ctx.Done():
			return
		case ev := <-e.queue:
			e.deliver(ctx, ev)
		}
	}
}

func (e *Emitter) deliver(ctx context.Context, ev Event) {
	for attempt := 0; attempt < e.attempts; attempt++ {
		err := e.send(ctx, ev)
		if err == nil {
			return
		}
		if !transport.Retryable(err) {
			slog.Warn("event rejected, dropping it", "type", ev.Type, "runner", ev.RunnerID, "error", err)
			return
		}
		if e.sleep(ctx, e.backoff.Delay(attempt)) != nil {
			return
		}
	}
	slog.Warn("event could not be delivered, dropping it", "type", ev.Type, "runner", ev.RunnerID)
}
