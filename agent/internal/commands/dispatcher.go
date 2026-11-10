package commands

import (
	"context"
	"fmt"
	"log/slog"
	"sync"
	"time"
)

const rememberLimit = 2048

type outcome struct {
	ok     bool
	result map[string]any
}

// Dispatcher fetches commands when poked (a realtime push) or on a timer, and runs each id once on a worker pool.
// A command seen again — the server redelivers until it is acked — is never re-executed: an in-flight one is
// ignored and a finished one just has its ack repeated.
type Dispatcher struct {
	API      API
	Interval time.Duration
	Workers  int
	Log      *slog.Logger

	mu       sync.Mutex
	handlers map[string]Handler
	state    map[string]*outcome // nil value = running
	order    []string
	poke     chan struct{}
}

func NewDispatcher(api API, interval time.Duration, workers int) *Dispatcher {
	return &Dispatcher{
		API: api, Interval: interval, Workers: max(workers, 1), Log: slog.Default(),
		handlers: map[string]Handler{}, state: map[string]*outcome{}, poke: make(chan struct{}, 1),
	}
}

// Register sets the handler for a command type. Call before Run.
func (d *Dispatcher) Register(commandType string, h Handler) { d.handlers[commandType] = h }

// Trigger asks for an immediate fetch; bursts collapse into one.
func (d *Dispatcher) Trigger() {
	select {
	case d.poke <- struct{}{}:
	default:
	}
}

// Run fetches until ctx ends, waiting for in-flight commands before returning.
func (d *Dispatcher) Run(ctx context.Context) {
	jobs := make(chan Command)
	var wg sync.WaitGroup
	for i := 0; i < d.Workers; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for cmd := range jobs {
				d.execute(ctx, cmd)
			}
		}()
	}

	ticker := time.NewTicker(d.Interval)
	defer ticker.Stop()

	d.fetch(ctx, jobs)
loop:
	for {
		select {
		case <-ctx.Done():
			break loop
		case <-ticker.C:
		case <-d.poke:
		}
		d.fetch(ctx, jobs)
	}
	close(jobs)
	wg.Wait()
}

func (d *Dispatcher) fetch(ctx context.Context, jobs chan<- Command) {
	cmds, err := d.API.Fetch(ctx)
	if err != nil {
		if ctx.Err() == nil {
			d.Log.Warn("fetching commands failed, will retry on the next poll", "error", err)
		}
		return
	}
	for _, cmd := range cmds {
		if !d.claim(ctx, cmd.ID) {
			continue
		}
		select {
		case jobs <- cmd:
		case <-ctx.Done():
			return
		}
	}
}

// claim reports whether cmd should be executed now. Known ids are handled here: a finished one is re-acked.
func (d *Dispatcher) claim(ctx context.Context, id string) bool {
	d.mu.Lock()
	known, seen := d.state[id]
	if !seen {
		d.state[id] = nil
		d.order = append(d.order, id)
		if len(d.order) > rememberLimit {
			delete(d.state, d.order[0])
			d.order = d.order[1:]
		}
	}
	d.mu.Unlock()

	if seen && known != nil {
		if err := d.API.Ack(ctx, id, known.ok, known.result); err != nil && ctx.Err() == nil {
			d.Log.Warn("repeating ack failed", "command", id, "error", err)
		}
	}

	return !seen
}

func (d *Dispatcher) execute(ctx context.Context, cmd Command) {
	out := d.run(ctx, cmd)

	d.mu.Lock()
	d.state[cmd.ID] = &out
	d.mu.Unlock()

	if err := d.API.Ack(ctx, cmd.ID, out.ok, out.result); err != nil && ctx.Err() == nil {
		// The server redelivers an unacked command; claim() then repeats this ack without re-running it.
		d.Log.Warn("ack failed, the server will redeliver", "command", cmd.ID, "error", err)
	}
}

func (d *Dispatcher) run(ctx context.Context, cmd Command) (out outcome) {
	h, ok := d.handlers[cmd.Type]
	if !ok {
		d.Log.Warn("unsupported command", "command", cmd.ID, "type", cmd.Type)
		return outcome{false, map[string]any{"error": ErrUnsupported.Error(), "type": cmd.Type}}
	}

	defer func() {
		if r := recover(); r != nil {
			out = outcome{false, map[string]any{"error": fmt.Sprintf("handler panicked: %v", r)}}
		}
	}()

	result, err := h(ctx, cmd)
	if err != nil {
		return outcome{false, map[string]any{"error": err.Error()}}
	}

	return outcome{true, result}
}
