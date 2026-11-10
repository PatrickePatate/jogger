package transport

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync"
	"sync/atomic"
	"testing"
	"time"

	"github.com/coder/websocket"
)

// fakeReverb speaks just enough Pusher protocol for the client under test.
type fakeReverb struct {
	srv      *httptest.Server
	sessions atomic.Int32
	mu       sync.Mutex
	subs     []string
	onReady  func(ctx context.Context, c *websocket.Conn) // runs once subscribed
}

func newFakeReverb(t *testing.T, onReady func(ctx context.Context, c *websocket.Conn)) *fakeReverb {
	f := &fakeReverb{onReady: onReady}
	f.srv = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c, err := websocket.Accept(w, r, nil)
		if err != nil {
			return
		}
		defer c.CloseNow()
		f.sessions.Add(1)
		ctx := r.Context()

		send := func(v map[string]any) { b, _ := json.Marshal(v); _ = c.Write(ctx, websocket.MessageText, b) }
		est, _ := json.Marshal(map[string]any{"socket_id": "123.456", "activity_timeout": 30})
		send(map[string]any{"event": "pusher:connection_established", "data": string(est)})

		for {
			_, raw, err := c.Read(ctx)
			if err != nil {
				return
			}
			var m struct {
				Event string `json:"event"`
				Data  struct {
					Auth    string `json:"auth"`
					Channel string `json:"channel"`
				} `json:"data"`
			}
			_ = json.Unmarshal(raw, &m)
			if m.Event == "pusher:subscribe" {
				f.mu.Lock()
				f.subs = append(f.subs, m.Data.Channel+"|"+m.Data.Auth)
				f.mu.Unlock()
				send(map[string]any{"event": "pusher_internal:subscription_succeeded", "channel": m.Data.Channel, "data": "{}"})
				if f.onReady != nil {
					f.onReady(ctx, c)
				}
			}
		}
	}))
	t.Cleanup(f.srv.Close)

	return f
}

func (f *fakeReverb) url() string { return "ws" + strings.TrimPrefix(f.srv.URL, "http") + "/app/key" }

func fastPusher(url string, onEvent func(event, data string)) *Pusher {
	return &Pusher{
		URL:       url,
		Channel:   "private-host.h1",
		Authorize: func(_ context.Context, socketID, channel string) (string, error) { return "key:sig-" + socketID, nil },
		OnEvent:   onEvent,
		Backoff:   &Backoff{Min: 10 * time.Millisecond, Max: 20 * time.Millisecond, Rand: func() float64 { return 0 }},
	}
}

func waitFor(t *testing.T, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if cond() {
			return
		}
		time.Sleep(5 * time.Millisecond)
	}
	t.Fatal("condition not met in time")
}

func TestReverbURL(t *testing.T) {
	if got := ReverbURL("https", "jogger.example", 443, "k"); got != "wss://jogger.example:443/app/k?client=jogger-agent&protocol=7" && got != "wss://jogger.example:443/app/k?protocol=7&client=jogger-agent" {
		t.Fatalf("got %s", got)
	}
	if got := ReverbURL("http", "localhost", 8080, "k"); !strings.HasPrefix(got, "ws://localhost:8080/app/k") {
		t.Fatalf("got %s", got)
	}
}

func TestPusherSubscribesAuthorizedAndDeliversEvents(t *testing.T) {
	reverb := newFakeReverb(t, func(ctx context.Context, c *websocket.Conn) {
		b, _ := json.Marshal(map[string]any{"event": "command.available", "channel": "private-host.h1", "data": `{"command_id":"c1"}`})
		_ = c.Write(ctx, websocket.MessageText, b)
		other, _ := json.Marshal(map[string]any{"event": "command.available", "channel": "private-host.other", "data": "{}"})
		_ = c.Write(ctx, websocket.MessageText, other)
	})

	var mu sync.Mutex
	var got []string
	connected := make(chan struct{}, 1)
	p := fastPusher(reverb.url(), func(event, data string) { mu.Lock(); got = append(got, event+" "+data); mu.Unlock() })
	p.OnConnect = func() { connected <- struct{}{} }

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go p.Run(ctx)

	waitFor(t, func() bool { mu.Lock(); defer mu.Unlock(); return len(got) >= 1 })
	time.Sleep(30 * time.Millisecond)
	mu.Lock()
	defer mu.Unlock()
	if len(got) != 1 || got[0] != `command.available {"command_id":"c1"}` {
		t.Fatalf("events = %v (events of other channels must be ignored)", got)
	}
	reverb.mu.Lock()
	defer reverb.mu.Unlock()
	if len(reverb.subs) != 1 || reverb.subs[0] != "private-host.h1|key:sig-123.456" {
		t.Fatalf("subscription = %v", reverb.subs)
	}
	select {
	case <-connected:
	default:
		t.Fatal("OnConnect was not called")
	}
}

func TestPusherAnswersPings(t *testing.T) {
	pong := make(chan struct{}, 1)
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		c, _ := websocket.Accept(w, r, nil)
		defer c.CloseNow()
		ctx := r.Context()
		est, _ := json.Marshal(map[string]any{"socket_id": "1.2"})
		b, _ := json.Marshal(map[string]any{"event": "pusher:connection_established", "data": string(est)})
		_ = c.Write(ctx, websocket.MessageText, b)
		_, _, _ = c.Read(ctx) // subscribe
		_ = c.Write(ctx, websocket.MessageText, []byte(`{"event":"pusher:ping","data":{}}`))
		_, raw, err := c.Read(ctx)
		if err == nil && strings.Contains(string(raw), "pusher:pong") {
			pong <- struct{}{}
		}
	}))
	defer srv.Close()

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go fastPusher("ws"+strings.TrimPrefix(srv.URL, "http")+"/app/k", nil).Run(ctx)

	select {
	case <-pong:
	case <-time.After(2 * time.Second):
		t.Fatal("no pong")
	}
}

func TestPusherReconnectsAfterDrop(t *testing.T) {
	reverb := newFakeReverb(t, func(ctx context.Context, c *websocket.Conn) {
		_ = c.Close(websocket.StatusGoingAway, "bye") // drop right after subscribing
	})
	var connects atomic.Int32
	p := fastPusher(reverb.url(), nil)
	p.OnConnect = func() { connects.Add(1) }

	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	go p.Run(ctx)

	waitFor(t, func() bool { return connects.Load() >= 3 })
}

func TestPusherKeepsRetryingWhileServerIsDown(t *testing.T) {
	srv := httptest.NewServer(http.NotFoundHandler()) // not a websocket endpoint
	url := "ws" + strings.TrimPrefix(srv.URL, "http") + "/app/k"
	var slept atomic.Int32
	p := fastPusher(url, nil)
	p.Sleep = func(ctx context.Context, d time.Duration) error { slept.Add(1); return Sleep(ctx, time.Millisecond) }

	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { p.Run(ctx); close(done) }()

	waitFor(t, func() bool { return slept.Load() >= 3 })
	cancel()
	select {
	case <-done:
	case <-time.After(2 * time.Second):
		t.Fatal("Run did not stop when the context ended")
	}
	srv.Close()
}
