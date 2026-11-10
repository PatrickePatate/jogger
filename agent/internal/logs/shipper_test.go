package logs

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"runtime"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/PatrickePatate/jogger/agent/internal/transport"
)

type server struct {
	mu      sync.Mutex
	down    bool
	batches []Batch
	seen    map[int]bool
}

func newServer() *server { return &server{seen: map[int]bool{}} }

func (s *server) send(_ context.Context, b Batch) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.down {
		return errors.New("connection refused")
	}
	if !s.seen[b.Seq] { // the real server de-dupes retries by seq
		s.seen[b.Seq] = true
		s.batches = append(s.batches, b)
	}

	return nil
}

func (s *server) setDown(v bool) { s.mu.Lock(); s.down = v; s.mu.Unlock() }
func (s *server) snapshot() []Batch {
	s.mu.Lock()
	defer s.mu.Unlock()

	return append([]Batch(nil), s.batches...)
}

func (s *server) text() string {
	var sb strings.Builder
	for _, b := range s.snapshot() {
		sb.WriteString(b.Data)
	}

	return sb.String()
}

func fast(sh *Shipper) *Shipper {
	sh.FlushEvery = 10 * time.Millisecond
	sh.Backoff = &transport.Backoff{Min: 5 * time.Millisecond, Max: 10 * time.Millisecond, Rand: func() float64 { return 0 }}

	return sh
}

func eventually(t *testing.T, within time.Duration, cond func() bool) {
	t.Helper()
	deadline := time.Now().Add(within)
	for time.Now().Before(deadline) {
		if cond() {
			return
		}
		time.Sleep(5 * time.Millisecond)
	}
	t.Fatal("condition not met in time")
}

func run(t *testing.T, sh *Shipper) context.Context {
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)
	go sh.Run(ctx)

	return ctx
}

func TestOutputIsShippedWithinOneSecond(t *testing.T) {
	srv := newServer()
	sh := New(srv.send) // production flush interval (250 ms)
	run(t, sh)

	began := time.Now()
	_, _ = sh.Write([]byte("hello\n"))
	eventually(t, time.Second, func() bool { return srv.text() == "hello\n" })
	if time.Since(began) > time.Second {
		t.Fatal("too slow")
	}
}

func TestNoGapsOrDuplicatesAfterOutage(t *testing.T) {
	srv := newServer()
	var attempts, rejected int
	var mu sync.Mutex
	// The server stores the batch but "loses" the response once: the shipper must resend and the server must dedupe.
	send := func(ctx context.Context, b Batch) error {
		err := srv.send(ctx, b)
		mu.Lock()
		defer mu.Unlock()
		attempts++
		if err == nil && b.Seq == 2 && rejected == 0 {
			rejected++
			return errors.New("response lost")
		}

		return err
	}
	sh := fast(New(send))
	sh.MaxBatch = 16
	run(t, sh)

	var want bytes.Buffer
	for i := 0; i < 10; i++ {
		fmt.Fprintf(&want, "line %02d\n", i)
		_, _ = sh.Write([]byte(fmt.Sprintf("line %02d\n", i)))
		if i == 3 {
			srv.setDown(true)
		}
		if i == 7 {
			srv.setDown(false)
		}
		time.Sleep(15 * time.Millisecond)
	}
	eventually(t, 3*time.Second, func() bool { return srv.text() == want.String() })

	for i, b := range srv.snapshot() {
		if b.Seq != i+1 {
			t.Fatalf("batch %d has seq %d: %+v", i, b.Seq, srv.snapshot())
		}
	}
	mu.Lock()
	defer mu.Unlock()
	if rejected != 1 || attempts <= len(srv.snapshot()) {
		t.Fatalf("expected a resend (attempts=%d, batches=%d)", attempts, len(srv.snapshot()))
	}
}

func TestBatchesRespectMaxSizeAndKeepLinesWhole(t *testing.T) {
	srv := newServer()
	sh := fast(New(srv.send))
	sh.MaxBatch = 32
	run(t, sh)

	var all strings.Builder
	for i := 0; i < 20; i++ {
		line := fmt.Sprintf("%02d-%s\n", i, strings.Repeat("x", 8))
		all.WriteString(line)
		_, _ = sh.Write([]byte(line))
	}
	eventually(t, 2*time.Second, func() bool { return srv.text() == all.String() })
	for _, b := range srv.snapshot() {
		if len(b.Data) > 32 || !strings.HasSuffix(b.Data, "\n") {
			t.Fatalf("bad batch %q", b.Data)
		}
	}
}

func TestMultibyteCharactersAreNotSplit(t *testing.T) {
	srv := newServer()
	sh := fast(New(srv.send))
	sh.MaxBatch = 10
	run(t, sh)

	text := strings.Repeat("é", 30) // 2 bytes each, no newlines
	_, _ = sh.Write([]byte(text))
	eventually(t, 2*time.Second, func() bool { return srv.text() == text })
}

func TestBufferCapHoldsUnderFlood(t *testing.T) {
	srv := newServer()
	srv.setDown(true)
	sh := fast(New(srv.send))
	sh.MaxBuffer = 1 << 20
	run(t, sh)

	chunk := bytes.Repeat([]byte("0123456789abcdef"), 1024) // 16 KB
	for i := 0; i < 640; i++ {                              // 10 MB
		_, _ = sh.Write(chunk)
	}
	sh.mu.Lock()
	held := sh.queued + len(sh.cur)
	sh.mu.Unlock()
	if held > sh.MaxBuffer {
		t.Fatalf("holding %d bytes, cap %d", held, sh.MaxBuffer)
	}
	var m runtime.MemStats
	runtime.GC()
	runtime.ReadMemStats(&m)
	if m.HeapAlloc > 64<<20 {
		t.Fatalf("heap = %d MB", m.HeapAlloc>>20)
	}

	// When the server returns, the oldest output is gone but a marker says so and seq has no gap.
	srv.setDown(false)
	eventually(t, 3*time.Second, func() bool { return len(srv.snapshot()) > 0 && len(srv.text()) > 0 })
	time.Sleep(200 * time.Millisecond)
	if !strings.Contains(srv.text(), "bytes of output dropped") {
		t.Fatal("no drop marker")
	}
	for i, b := range srv.snapshot() {
		if b.Seq != i+1 {
			t.Fatalf("seq gap at %d: %d", i, b.Seq)
		}
	}
}

func TestFinishSendsEOFAfterAllOutput(t *testing.T) {
	srv := newServer()
	sh := fast(New(srv.send))
	run(t, sh)

	_, _ = sh.Write([]byte("last words"))
	sh.Finish(context.Background())

	got := srv.snapshot()
	if len(got) < 2 || !got[len(got)-1].EOF || srv.text() != "last words" {
		t.Fatalf("batches = %+v", got)
	}
	for _, b := range got[:len(got)-1] {
		if b.EOF {
			t.Fatal("eof before the end")
		}
	}
	// Run has stopped by itself, and later writes are ignored.
	_, _ = sh.Write([]byte("ignored"))
	if strings.Contains(srv.text(), "ignored") {
		t.Fatal("wrote after finish")
	}
}

func TestFinishWaitsOutAnOutage(t *testing.T) {
	srv := newServer()
	srv.setDown(true)
	sh := fast(New(srv.send))
	run(t, sh)
	_, _ = sh.Write([]byte("x"))

	go func() { time.Sleep(100 * time.Millisecond); srv.setDown(false) }()
	sh.Finish(context.Background())
	if got := srv.snapshot(); len(got) < 2 || !got[len(got)-1].EOF {
		t.Fatalf("eof not delivered: %+v", got)
	}
}

func TestRejectedBatchIsDiscardedNotRetriedForever(t *testing.T) {
	var mu sync.Mutex
	calls := 0
	sh := fast(New(func(context.Context, Batch) error {
		mu.Lock()
		defer mu.Unlock()
		calls++
		return &transport.APIError{Status: 404}
	}))
	run(t, sh)
	_, _ = sh.Write([]byte("x"))
	sh.Finish(context.Background())

	mu.Lock()
	defer mu.Unlock()
	if calls != 2 { // the data batch and the eof batch, once each
		t.Fatalf("calls = %d", calls)
	}
}
