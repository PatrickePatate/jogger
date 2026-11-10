package logs

import (
	"context"
	"log/slog"
	"time"

	"github.com/PatrickePatate/jogger/agent/internal/docker"
)

// Follow ships one runner container's output: it streams the engine's log into a shipper and, when the
// container ends, sends the final eof batch. It returns once everything is delivered (or finishCtx gives up).
func Follow(ctx context.Context, d *docker.Client, containerID, runnerID string, send Sender) {
	sh := New(send)
	runCtx, stopRun := context.WithCancel(ctx)
	defer stopRun()
	go sh.Run(runCtx)

	since := time.Time{}
	for ctx.Err() == nil {
		err := d.StreamLogs(ctx, containerID, since, sh)
		if err == nil || docker.IsNotFound(err) {
			break // the container ended (or is already gone)
		}
		slog.Warn("log stream interrupted, resuming", "runner", runnerID, "error", err)
		since = time.Now().Add(-time.Second) // tolerate one second of repeats rather than a gap
		select {
		case <-ctx.Done():
		case <-time.After(time.Second):
		}
	}

	finish, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
	defer cancel()
	sh.Finish(finish)
}
