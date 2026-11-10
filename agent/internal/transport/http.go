// Package transport is the agent's HTTPS client for the control plane.
package transport

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math/rand/v2"
	"net/http"
	"strings"
	"time"
)

// ProtocolVersion is sent on every request so the server can refuse agents it cannot talk to.
const ProtocolVersion = "1"

// ErrRevoked means the server no longer accepts this agent's credential. Retrying cannot help.
var ErrRevoked = errors.New("the server rejected this agent's credential (host revoked or removed)")

// APIError is the server's JSON error envelope: {"error": {"code", "message"}}.
type APIError struct {
	Status  int
	Code    string
	Message string
}

func (e *APIError) Error() string {
	return fmt.Sprintf("server returned %d %s: %s", e.Status, e.Code, e.Message)
}

// Retryable reports whether trying again later could succeed: server trouble and rate limits yes, rejections no.
func (e *APIError) Retryable() bool {
	return e.Status >= 500 || e.Status == http.StatusTooManyRequests || e.Status == http.StatusRequestTimeout
}

// Client talks to one control plane with an optional bearer credential.
type Client struct {
	BaseURL    string
	Credential string
	UserAgent  string
	HTTP       *http.Client
}

func New(baseURL, credential, agentVersion string) *Client {
	return &Client{
		BaseURL:    strings.TrimRight(baseURL, "/"),
		Credential: credential,
		UserAgent:  "jogger-agent/" + agentVersion,
		HTTP:       &http.Client{Timeout: 20 * time.Second},
	}
}

// Do sends a JSON request and decodes a JSON response into out (which may be nil).
func (c *Client) Do(ctx context.Context, method, path string, body, out any) error {
	var reader io.Reader
	if body != nil {
		payload, err := json.Marshal(body)
		if err != nil {
			return fmt.Errorf("encoding request: %w", err)
		}
		reader = bytes.NewReader(payload)
	}

	req, err := http.NewRequestWithContext(ctx, method, c.BaseURL+path, reader)
	if err != nil {
		return err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("X-Jogger-Agent-Protocol", ProtocolVersion)
	req.Header.Set("User-Agent", c.UserAgent)
	if body != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if c.Credential != "" {
		req.Header.Set("Authorization", "Bearer "+c.Credential)
	}

	resp, err := c.HTTP.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	data, err := io.ReadAll(io.LimitReader(resp.Body, 4<<20))
	if err != nil {
		return err
	}

	if resp.StatusCode >= 400 {
		apiErr := parseError(resp.StatusCode, data)
		if apiErr.Code == "INVALID_AGENT_CREDENTIAL" {
			return fmt.Errorf("%w: %s", ErrRevoked, apiErr.Message)
		}

		return apiErr
	}
	if out != nil && len(data) > 0 {
		if err := json.Unmarshal(data, out); err != nil {
			return fmt.Errorf("decoding response: %w", err)
		}
	}

	return nil
}

func parseError(status int, data []byte) *APIError {
	var envelope struct {
		Error struct {
			Code    string `json:"code"`
			Message string `json:"message"`
		} `json:"error"`
	}
	apiErr := &APIError{Status: status, Code: "HTTP_ERROR", Message: http.StatusText(status)}
	if json.Unmarshal(data, &envelope) == nil && envelope.Error.Code != "" {
		apiErr.Code, apiErr.Message = envelope.Error.Code, envelope.Error.Message
	}

	return apiErr
}

// Backoff computes capped exponential delays with jitter: about 1 s, 2 s, 4 s … up to Max (60 s).
type Backoff struct {
	Min, Max time.Duration
	// Rand returns a number in [0,1); replaceable in tests.
	Rand func() float64
}

func NewBackoff() *Backoff {
	return &Backoff{Min: time.Second, Max: 60 * time.Second, Rand: rand.Float64}
}

// Delay is the wait before retry number attempt (0 = the first retry).
func (b *Backoff) Delay(attempt int) time.Duration {
	d := b.Min
	for i := 0; i < attempt && d < b.Max; i++ {
		d *= 2
	}
	if d > b.Max {
		d = b.Max
	}
	d += time.Duration(float64(d) / 4 * b.Rand()) // up to +25 % jitter, never below Min

	return min(d, b.Max)
}

// Retryable reports whether err is worth retrying: network failures and 5xx/429 yes; rejections and revocation no.
func Retryable(err error) bool {
	if err == nil || errors.Is(err, ErrRevoked) || errors.Is(err, context.Canceled) {
		return false
	}
	var apiErr *APIError
	if errors.As(err, &apiErr) {
		return apiErr.Retryable()
	}

	return true
}

// Retry runs fn until it succeeds, fails permanently, or ctx ends, sleeping per the backoff between attempts.
// maxAttempts <= 0 retries forever.
func Retry(ctx context.Context, b *Backoff, maxAttempts int, sleep func(context.Context, time.Duration) error, fn func(context.Context) error) error {
	for attempt := 0; ; attempt++ {
		err := fn(ctx)
		if err == nil || !Retryable(err) {
			return err
		}
		if maxAttempts > 0 && attempt+1 >= maxAttempts {
			return err
		}
		if serr := sleep(ctx, b.Delay(attempt)); serr != nil {
			return serr
		}
	}
}

// Sleep waits for d or until ctx is cancelled.
func Sleep(ctx context.Context, d time.Duration) error {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}
