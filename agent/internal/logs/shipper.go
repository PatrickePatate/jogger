// Package logs ships a runner's output to the control plane in numbered batches that survive outages.
package logs

import (
	"context"
	"fmt"
	"log/slog"
	"sync"
	"time"
	"unicode/utf8"

	"github.com/PatrickePatate/jogger/agent/internal/transport"
)

// Batch is one request body. Seq starts at 1 per runner and never has gaps, so the server can de-dupe retries
// and detect loss. EOF marks the final batch.
type Batch struct {
	Seq  int    `json:"seq"`
	Data string `json:"data"`
	EOF  bool   `json:"eof,omitempty"`
}

// Sender delivers one batch; returning nil means the server has it.
type Sender func(ctx context.Context, b Batch) error

type pending struct {
	data []byte
	seq  int // 0 until the batch is first offered to the server
	eof  bool
}

// Shipper collects output (Write), cuts it into batches of at most MaxBatch bytes or every FlushEvery, and sends
// them in order, resending the same batch until the server accepts it. Memory is bounded by MaxBuffer: when the
// server stays away, the oldest never-sent batches are dropped and a marker line says how much.
type Shipper struct {
	Send       Sender
	MaxBatch   int
	FlushEvery time.Duration
	MaxBuffer  int
	Backoff    *transport.Backoff
	Sleep      func(context.Context, time.Duration) error

	mu      sync.Mutex
	cur     []byte
	queue   []*pending
	queued  int // bytes held in queue
	nextSeq int
	dropped int
	closed  bool
	wake    chan struct{}
	done    chan struct{} // closed when Run returns
}

func New(send Sender) *Shipper {
	return &Shipper{
		Send: send, MaxBatch: 64 << 10, FlushEvery: 250 * time.Millisecond, MaxBuffer: 8 << 20,
		Backoff: transport.NewBackoff(), Sleep: transport.Sleep,
		nextSeq: 1, wake: make(chan struct{}, 1), done: make(chan struct{}),
	}
}

// Write buffers output; it never blocks on the network and never fails.
func (s *Shipper) Write(p []byte) (int, error) {
	s.mu.Lock()
	if s.closed {
		s.mu.Unlock()
		return len(p), nil
	}
	s.cur = append(s.cur, p...)
	for len(s.cur) >= s.MaxBatch {
		s.cutLocked(s.MaxBatch)
	}
	s.enforceLimitLocked()
	s.mu.Unlock()
	s.signal()

	return len(p), nil
}

// cutLocked moves up to limit bytes of cur into a new batch, preferring to end on a line break and never
// splitting a UTF-8 character.
func (s *Shipper) cutLocked(limit int) {
	n := min(limit, len(s.cur))
	if n < len(s.cur) {
		if i := lastIndexByte(s.cur[:n], '\n'); i >= 0 {
			n = i + 1
		} else {
			for n > 0 && !utf8.RuneStart(s.cur[n]) {
				n--
			}
			if n == 0 {
				n = min(limit, len(s.cur))
			}
		}
	}
	data := append([]byte(nil), s.cur[:n]...)
	s.cur = s.cur[n:]
	if s.dropped > 0 {
		data = append([]byte(fmt.Sprintf("[jogger: %d bytes of output dropped while the server was unreachable]\n", s.dropped)), data...)
		s.dropped = 0
	}
	s.queue = append(s.queue, &pending{data: data})
	s.queued += len(data)
}

func lastIndexByte(b []byte, c byte) int {
	for i := len(b) - 1; i >= 0; i-- {
		if b[i] == c {
			return i
		}
	}

	return -1
}

// enforceLimitLocked drops the oldest batches the server has never seen until the buffer fits. A batch that was
// already offered keeps its place: the server may hold it, and removing it would leave a gap in seq.
func (s *Shipper) enforceLimitLocked() {
	for s.queued+len(s.cur) > s.MaxBuffer {
		i := 0
		for i < len(s.queue) && s.queue[i].seq != 0 {
			i++
		}
		if i == len(s.queue) {
			// Nothing droppable is queued; shed the open buffer itself.
			s.dropped += len(s.cur)
			s.cur = s.cur[:0]
			return
		}
		s.dropped += len(s.queue[i].data)
		s.queued -= len(s.queue[i].data)
		s.queue = append(s.queue[:i], s.queue[i+1:]...)
	}
}

func (s *Shipper) signal() {
	select {
	case s.wake <- struct{}{}:
	default:
	}
}

// Run sends batches until ctx ends or Finish has drained everything.
func (s *Shipper) Run(ctx context.Context) {
	defer close(s.done)
	ticker := time.NewTicker(s.FlushEvery)
	defer ticker.Stop()
	attempt := 0

	for {
		head := s.next()
		if head == nil {
			s.mu.Lock()
			done := s.closed && len(s.queue) == 0 && len(s.cur) == 0
			s.mu.Unlock()
			if done {
				return
			}
			select {
			case <-ctx.Done():
				return
			case <-s.wake:
			case <-ticker.C:
				s.mu.Lock()
				if len(s.cur) > 0 {
					s.cutLocked(len(s.cur))
				}
				s.mu.Unlock()
			}
			continue
		}

		if err := s.Send(ctx, Batch{Seq: head.seq, Data: string(head.data), EOF: head.eof}); err != nil {
			if ctx.Err() != nil {
				return
			}
			if transport.Retryable(err) {
				if s.Sleep(ctx, s.Backoff.Delay(attempt)) != nil {
					return
				}
				attempt++
				continue
			}
			// The server refused this batch (unknown runner, bad request): retrying cannot help, so move on.
			slog.Warn("log batch rejected, discarding it", "seq", head.seq, "error", err)
		}
		attempt = 0
		s.mu.Lock()
		s.queue = s.queue[1:]
		s.queued -= len(head.data)
		s.mu.Unlock()
	}
}

// next returns the head batch, numbering it the first time it is offered.
func (s *Shipper) next() *pending {
	s.mu.Lock()
	defer s.mu.Unlock()
	if len(s.queue) == 0 {
		return nil
	}
	head := s.queue[0]
	if head.seq == 0 {
		head.seq = s.nextSeq
		s.nextSeq++
	}

	return head
}

// Finish flushes the remaining output, queues the final eof batch and waits until everything is delivered or ctx
// ends. Run must be running.
func (s *Shipper) Finish(ctx context.Context) {
	s.mu.Lock()
	if !s.closed {
		if len(s.cur) > 0 {
			s.cutLocked(len(s.cur))
		}
		s.queue = append(s.queue, &pending{eof: true})
		s.closed = true
	}
	s.mu.Unlock()
	s.signal()

	select {
	case <-s.done:
	case <-ctx.Done():
	}
}
