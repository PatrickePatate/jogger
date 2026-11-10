package commands

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"sync"
	"sync/atomic"
	"testing"
	"time"
)

type ack struct {
	id     string
	ok     bool
	result map[string]any
}

type fakeAPI struct {
	mu      sync.Mutex
	queue   [][]Command // each Fetch pops one batch
	acks    []ack
	ackErr  error
	fetches int
}

func (f *fakeAPI) Fetch(context.Context) ([]Command, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.fetches++
	if len(f.queue) == 0 {
		return nil, nil
	}
	batch := f.queue[0]
	f.queue = f.queue[1:]

	return batch, nil
}

func (f *fakeAPI) Ack(_ context.Context, id string, ok bool, result map[string]any) error {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.acks = append(f.acks, ack{id, ok, result})

	return f.ackErr
}

func (f *fakeAPI) ackList() []ack {
	f.mu.Lock()
	defer f.mu.Unlock()

	return append([]ack(nil), f.acks...)
}

func (f *fakeAPI) push(cmds ...Command) {
	f.mu.Lock()
	f.queue = append(f.queue, cmds)
	f.mu.Unlock()
}

func start(t *testing.T, d *Dispatcher) {
	t.Helper()
	d.Log = slog.New(slog.NewTextHandler(io.Discard, nil))
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { d.Run(ctx); close(done) }()
	t.Cleanup(func() { cancel(); <-done })
}

func eventually(t *testing.T, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		if cond() {
			return
		}
		time.Sleep(5 * time.Millisecond)
	}
	t.Fatal("condition not met in time")
}

func TestTriggerFetchesImmediately(t *testing.T) {
	api := &fakeAPI{}
	d := NewDispatcher(api, time.Hour, 2) // the timer never fires: only a push can cause the fetch
	d.Register("ping", func(context.Context, Command) (map[string]any, error) { return map[string]any{"pong": true}, nil })
	start(t, d)
	eventually(t, func() bool { api.mu.Lock(); defer api.mu.Unlock(); return api.fetches >= 1 }) // initial fetch

	api.push(Command{ID: "a", Type: "ping"})
	began := time.Now()
	d.Trigger()
	eventually(t, func() bool { return len(api.ackList()) == 1 })

	if time.Since(began) > time.Second {
		t.Fatalf("push took %s to execute", time.Since(began))
	}
	if got := api.ackList()[0]; !got.ok || got.result["pong"] != true {
		t.Fatalf("unexpected ack %+v", got)
	}
}

func TestPollingWorksWithoutPushes(t *testing.T) {
	api := &fakeAPI{}
	d := NewDispatcher(api, 20*time.Millisecond, 1)
	d.Register("ping", func(context.Context, Command) (map[string]any, error) { return nil, nil })
	start(t, d)

	api.push(Command{ID: "a", Type: "ping"})
	eventually(t, func() bool { return len(api.ackList()) == 1 })
}

func TestSameCommandRunsOnce(t *testing.T) {
	api := &fakeAPI{}
	var runs atomic.Int32
	d := NewDispatcher(api, 10*time.Millisecond, 2)
	d.Register("ping", func(context.Context, Command) (map[string]any, error) {
		runs.Add(1)
		return map[string]any{"n": 1}, nil
	})
	cmd := Command{ID: "dup", Type: "ping"}
	api.push(cmd, cmd) // twice in one batch
	api.push(cmd)      // and again on the next poll
	start(t, d)

	// Redeliveries after completion repeat the ack, never the work.
	eventually(t, func() bool { return len(api.ackList()) >= 2 })
	time.Sleep(50 * time.Millisecond)

	if runs.Load() != 1 {
		t.Fatalf("handler ran %d times, want 1", runs.Load())
	}
	for _, a := range api.ackList() {
		if !a.ok || a.result["n"] != 1 {
			t.Fatalf("repeated ack differs from the first: %+v", a)
		}
	}
}

func TestInFlightRedeliveryIsIgnored(t *testing.T) {
	api := &fakeAPI{}
	release := make(chan struct{})
	var runs atomic.Int32
	d := NewDispatcher(api, 10*time.Millisecond, 2)
	d.Register("slow", func(context.Context, Command) (map[string]any, error) {
		runs.Add(1)
		<-release
		return nil, nil
	})
	api.push(Command{ID: "s", Type: "slow"})
	api.push(Command{ID: "s", Type: "slow"})
	start(t, d)

	eventually(t, func() bool { api.mu.Lock(); defer api.mu.Unlock(); return api.fetches >= 3 })
	if len(api.ackList()) != 0 {
		t.Fatal("acked before the handler finished")
	}
	close(release)
	eventually(t, func() bool { return len(api.ackList()) == 1 })
	if runs.Load() != 1 {
		t.Fatalf("handler ran %d times", runs.Load())
	}
}

func TestUnknownTypeIsAckedUnsupported(t *testing.T) {
	api := &fakeAPI{}
	d := NewDispatcher(api, 10*time.Millisecond, 1)
	api.push(Command{ID: "u", Type: "teleport"})
	start(t, d)

	eventually(t, func() bool { return len(api.ackList()) == 1 })
	got := api.ackList()[0]
	if got.ok || got.result["error"] != "unsupported" {
		t.Fatalf("got %+v", got)
	}
}

func TestHandlerErrorAndPanicAreReported(t *testing.T) {
	api := &fakeAPI{}
	d := NewDispatcher(api, 10*time.Millisecond, 1)
	d.Register("bad", func(context.Context, Command) (map[string]any, error) { return nil, errors.New("disk full") })
	d.Register("boom", func(context.Context, Command) (map[string]any, error) { panic("oops") })
	api.push(Command{ID: "1", Type: "bad"}, Command{ID: "2", Type: "boom"})
	start(t, d)

	eventually(t, func() bool { return len(api.ackList()) == 2 })
	for _, a := range api.ackList() {
		if a.ok || a.result["error"] == nil {
			t.Fatalf("expected a failure ack, got %+v", a)
		}
	}
}

func TestFailedAckIsRetriedOnRedelivery(t *testing.T) {
	api := &fakeAPI{ackErr: errors.New("network")}
	var runs atomic.Int32
	d := NewDispatcher(api, 10*time.Millisecond, 1)
	d.Register("ping", func(context.Context, Command) (map[string]any, error) { runs.Add(1); return nil, nil })
	api.push(Command{ID: "r", Type: "ping"})
	start(t, d)
	eventually(t, func() bool { return len(api.ackList()) == 1 })

	api.mu.Lock()
	api.ackErr = nil
	api.mu.Unlock()
	api.push(Command{ID: "r", Type: "ping"}) // the server redelivers because it never saw the ack
	eventually(t, func() bool { return len(api.ackList()) == 2 })

	if runs.Load() != 1 {
		t.Fatalf("handler ran %d times", runs.Load())
	}
}
