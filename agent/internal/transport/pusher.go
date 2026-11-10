package transport

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"net/url"
	"time"

	"github.com/coder/websocket"
)

// Pusher is a minimal Pusher-protocol client, enough to hold one private channel on Reverb. The socket is only
// a hint channel: everything it carries can also be fetched over HTTPS, so it reconnects quietly forever.
type Pusher struct {
	URL     string // ws(s)://host:port/app/{key}
	Channel string
	// Authorize returns the signature for subscribing to a private channel.
	Authorize func(ctx context.Context, socketID, channel string) (string, error)
	// OnEvent receives every application event on the channel (data is the raw payload string).
	OnEvent func(event, data string)
	// OnConnect runs after each successful subscription, so callers can catch up on anything missed.
	OnConnect func()
	Backoff   *Backoff
	Sleep     func(ctx context.Context, d time.Duration) error
}

// ReverbURL builds the socket address from what enrollment returned.
func ReverbURL(scheme, host string, port int, key string) string {
	ws := "wss"
	if scheme == "http" {
		ws = "ws"
	}
	u := url.URL{Scheme: ws, Host: fmt.Sprintf("%s:%d", host, port), Path: "/app/" + key, RawQuery: "protocol=7&client=jogger-agent"}

	return u.String()
}

type frame struct {
	Event   string `json:"event"`
	Channel string `json:"channel,omitempty"`
	Data    any    `json:"data,omitempty"`
}

// Run connects, subscribes and reads until ctx ends, reconnecting with backoff after every failure.
func (p *Pusher) Run(ctx context.Context) {
	sleep, backoff := p.Sleep, p.Backoff
	attempt := 0
	if sleep == nil {
		sleep = Sleep
	}
	if backoff == nil {
		backoff = NewBackoff()
	}

	for ctx.Err() == nil {
		subscribed, err := p.session(ctx)
		if ctx.Err() != nil {
			return
		}
		if subscribed {
			attempt = 0
		}
		delay := backoff.Delay(attempt)
		attempt++
		slog.Warn("realtime connection lost, polling continues", "error", err, "retry_in", delay.Round(time.Millisecond))
		if sleep(ctx, delay) != nil {
			return
		}
	}
}

// session runs one connection; it reports whether the subscription succeeded before the connection ended.
func (p *Pusher) session(ctx context.Context) (subscribed bool, err error) {
	dialCtx, cancel := context.WithTimeout(ctx, 15*time.Second)
	conn, _, err := websocket.Dial(dialCtx, p.URL, nil)
	cancel()
	if err != nil {
		return false, err
	}
	defer conn.CloseNow()
	conn.SetReadLimit(1 << 20)

	activity := 30 * time.Second
	for {
		// A silent socket for longer than the server's activity timeout (plus slack) is dead.
		readCtx, stop := context.WithTimeout(ctx, 2*activity+10*time.Second)
		_, raw, err := conn.Read(readCtx)
		stop()
		if err != nil {
			return subscribed, err
		}

		var f struct {
			Event   string          `json:"event"`
			Channel string          `json:"channel"`
			Data    json.RawMessage `json:"data"`
		}
		if json.Unmarshal(raw, &f) != nil {
			continue
		}
		data := payloadString(f.Data)

		switch f.Event {
		case "pusher:connection_established":
			var d struct {
				SocketID        string `json:"socket_id"`
				ActivityTimeout int    `json:"activity_timeout"`
			}
			if json.Unmarshal([]byte(data), &d) != nil || d.SocketID == "" {
				return subscribed, fmt.Errorf("malformed connection_established")
			}
			if d.ActivityTimeout > 0 {
				activity = time.Duration(d.ActivityTimeout) * time.Second
			}
			auth, err := p.Authorize(ctx, d.SocketID, p.Channel)
			if err != nil {
				return subscribed, fmt.Errorf("authorizing %s: %w", p.Channel, err)
			}
			if err := writeJSON(ctx, conn, frame{Event: "pusher:subscribe", Data: map[string]string{"auth": auth, "channel": p.Channel}}); err != nil {
				return subscribed, err
			}
		case "pusher_internal:subscription_succeeded":
			subscribed = true
			if p.OnConnect != nil {
				p.OnConnect()
			}
		case "pusher:ping":
			if err := writeJSON(ctx, conn, frame{Event: "pusher:pong", Data: map[string]string{}}); err != nil {
				return subscribed, err
			}
		case "pusher:error":
			return subscribed, fmt.Errorf("server error: %s", data)
		default:
			if f.Channel == p.Channel && p.OnEvent != nil {
				p.OnEvent(f.Event, data)
			}
		}
	}
}

func writeJSON(ctx context.Context, conn *websocket.Conn, v any) error {
	payload, err := json.Marshal(v)
	if err != nil {
		return err
	}
	wctx, cancel := context.WithTimeout(ctx, 10*time.Second)
	defer cancel()

	return conn.Write(wctx, websocket.MessageText, payload)
}

// payloadString returns the event data as text: Pusher sends it as a JSON string for application events and as
// a bare object for some protocol messages.
func payloadString(raw json.RawMessage) string {
	var s string
	if json.Unmarshal(raw, &s) == nil {
		return s
	}

	return string(raw)
}
