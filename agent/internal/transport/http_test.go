package transport

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

func TestRequestsCarryCredentialAndProtocolHeaders(t *testing.T) {
	var got http.Header
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		got = r.Header.Clone()
		w.Write([]byte(`{"status":"online"}`))
	}))
	defer srv.Close()

	var out struct{ Status string }
	if err := New(srv.URL, "jha_secret", "9.9").Do(context.Background(), "POST", "/agent/v1/heartbeat", map[string]int{"a": 1}, &out); err != nil {
		t.Fatal(err)
	}
	if got.Get("Authorization") != "Bearer jha_secret" || got.Get("X-Jogger-Agent-Protocol") != "1" ||
		got.Get("Content-Type") != "application/json" || got.Get("User-Agent") != "jogger-agent/9.9" {
		t.Errorf("unexpected headers: %v", got)
	}
	if out.Status != "online" {
		t.Errorf("response not decoded: %+v", out)
	}
}

func TestNoAuthorizationHeaderWithoutCredential(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "" {
			t.Error("enrollment must not send a bearer header")
		}
	}))
	defer srv.Close()

	if err := New(srv.URL, "", "1").Do(context.Background(), "GET", "/", nil, nil); err != nil {
		t.Fatal(err)
	}
}

func TestErrorEnvelopeIsParsed(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(401)
		w.Write([]byte(`{"error":{"code":"INVALID_ENROLLMENT_TOKEN","message":"used"}}`))
	}))
	defer srv.Close()

	err := New(srv.URL, "", "1").Do(context.Background(), "POST", "/agent/v1/enroll", map[string]string{}, nil)
	var apiErr *APIError
	if !errors.As(err, &apiErr) || apiErr.Code != "INVALID_ENROLLMENT_TOKEN" || apiErr.Status != 401 {
		t.Fatalf("got %v", err)
	}
	if Retryable(err) {
		t.Error("a rejected token must not be retried")
	}
}

func TestRevokedCredentialIsPermanent(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(401)
		w.Write([]byte(`{"error":{"code":"INVALID_AGENT_CREDENTIAL","message":"revoked"}}`))
	}))
	defer srv.Close()

	err := New(srv.URL, "jha_x", "1").Do(context.Background(), "GET", "/agent/v1/me", nil, nil)
	if !errors.Is(err, ErrRevoked) || Retryable(err) {
		t.Fatalf("got %v", err)
	}
}

func TestRetryableClassification(t *testing.T) {
	cases := map[string]struct {
		err  error
		want bool
	}{
		"network":  {errors.New("connection refused"), true},
		"500":      {&APIError{Status: 500}, true},
		"503":      {&APIError{Status: 503}, true},
		"429":      {&APIError{Status: 429}, true},
		"422":      {&APIError{Status: 422}, false},
		"404":      {&APIError{Status: 404}, false},
		"revoked":  {ErrRevoked, false},
		"canceled": {context.Canceled, false},
		"nil":      {nil, false},
	}
	for name, c := range cases {
		if got := Retryable(c.err); got != c.want {
			t.Errorf("%s: Retryable = %v, want %v", name, got, c.want)
		}
	}
}

func TestBackoffGrowsAndIsCapped(t *testing.T) {
	b := &Backoff{Min: time.Second, Max: 60 * time.Second, Rand: func() float64 { return 0 }}
	want := []time.Duration{1, 2, 4, 8, 16, 32, 60, 60}
	for i, w := range want {
		if got := b.Delay(i); got != w*time.Second {
			t.Errorf("attempt %d: %v, want %v", i, got, w*time.Second)
		}
	}

	b.Rand = func() float64 { return 0.999 }
	if got := b.Delay(0); got < time.Second || got > 1250*time.Millisecond {
		t.Errorf("jitter out of range: %v", got)
	}
	if got := b.Delay(30); got != 60*time.Second {
		t.Errorf("must never exceed Max, got %v", got)
	}
}

func TestRetryRetriesTransientFailuresThenSucceeds(t *testing.T) {
	var calls int32
	var slept []time.Duration
	b := &Backoff{Min: time.Second, Max: time.Minute, Rand: func() float64 { return 0 }}

	err := Retry(context.Background(), b, 0, func(_ context.Context, d time.Duration) error { slept = append(slept, d); return nil },
		func(context.Context) error {
			if atomic.AddInt32(&calls, 1) < 4 {
				return &APIError{Status: 503}
			}

			return nil
		})
	if err != nil || calls != 4 {
		t.Fatalf("err=%v calls=%d", err, calls)
	}
	if len(slept) != 3 || slept[0] != time.Second || slept[2] != 4*time.Second {
		t.Errorf("unexpected delays: %v", slept)
	}
}

func TestRetryStopsOnPermanentErrorsAndAtTheLimit(t *testing.T) {
	b := &Backoff{Min: time.Second, Max: time.Minute, Rand: func() float64 { return 0 }}
	noSleep := func(context.Context, time.Duration) error { return nil }

	calls := 0
	err := Retry(context.Background(), b, 0, noSleep, func(context.Context) error { calls++; return &APIError{Status: 401} })
	if calls != 1 || err == nil {
		t.Errorf("a permanent error must not be retried (calls=%d)", calls)
	}

	calls = 0
	err = Retry(context.Background(), b, 3, noSleep, func(context.Context) error { calls++; return errors.New("down") })
	if calls != 3 || err == nil {
		t.Errorf("must stop after 3 attempts (calls=%d)", calls)
	}
}

func TestRetryStopsWhenContextEnds(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	b := NewBackoff()
	err := Retry(ctx, b, 0, func(ctx context.Context, _ time.Duration) error { cancel(); return ctx.Err() },
		func(context.Context) error { return errors.New("down") })
	if !errors.Is(err, context.Canceled) {
		t.Errorf("got %v", err)
	}
}
