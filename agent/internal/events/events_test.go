package events

import (
	"context"
	"errors"
	"sync"
	"testing"
	"time"

	"github.com/PatrickePatate/jogger/agent/internal/transport"
)

func TestDeliversInOrderAndRetriesTransientFailures(t *testing.T) {
	var mu sync.Mutex
	var got []string
	calls := 0
	em := NewEmitter(func(_ context.Context, e Event) error {
		mu.Lock()
		defer mu.Unlock()
		calls++
		if calls == 1 {
			return &transport.APIError{Status: 503}
		}
		got = append(got, e.Type)
		return nil
	}, 8)
	em.sleep = func(context.Context, time.Duration) error { return nil }

	em.Emit(Event{Type: "a"})
	em.Emit(Event{Type: "b"})
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go em.Run(ctx)

	deadline := time.Now().Add(2 * time.Second)
	for time.Now().Before(deadline) {
		mu.Lock()
		n := len(got)
		mu.Unlock()
		if n == 2 {
			break
		}
		time.Sleep(5 * time.Millisecond)
	}
	mu.Lock()
	defer mu.Unlock()
	if len(got) != 2 || got[0] != "a" || got[1] != "b" || calls != 3 {
		t.Fatalf("got %v after %d calls", got, calls)
	}
}

func TestRejectedEventIsDroppedNotRetried(t *testing.T) {
	calls := 0
	em := NewEmitter(func(context.Context, Event) error { calls++; return &transport.APIError{Status: 404} }, 4)
	em.deliver(context.Background(), Event{Type: "x"})
	if calls != 1 {
		t.Fatalf("calls = %d", calls)
	}
}

func TestGivesUpAfterAttempts(t *testing.T) {
	calls := 0
	em := NewEmitter(func(context.Context, Event) error { calls++; return errors.New("net down") }, 4)
	em.sleep = func(context.Context, time.Duration) error { return nil }
	em.deliver(context.Background(), Event{Type: "x"})
	if calls != em.attempts {
		t.Fatalf("calls = %d", calls)
	}
}

func TestFullQueueDropsOldest(t *testing.T) {
	em := NewEmitter(nil, 2)
	em.Emit(Event{Type: "1"})
	em.Emit(Event{Type: "2"})
	em.Emit(Event{Type: "3"})
	if a, b := (<-em.queue).Type, (<-em.queue).Type; a != "2" || b != "3" {
		t.Fatalf("kept %s,%s", a, b)
	}
}
