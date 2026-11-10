package docker

import (
	"context"
	"encoding/json"
	"errors"
	"io"
	"log/slog"
	"strconv"
	"time"
)

// Exit is a runner container that has stopped.
type Exit struct {
	RunnerID string
	ExitCode int
	OOM      bool
}

// Watch follows engine events for this host's runner containers and calls onExit for each one that dies, after
// removing the runner's private network (AutoRemove deletes the container itself). It reconnects until ctx ends,
// resuming from the last event seen so exits during a blip are not lost.
func (r *Runner) Watch(ctx context.Context, onExit func(Exit)) {
	since := time.Now()
	oom := map[string]bool{} // container id -> was OOM-killed

	for ctx.Err() == nil {
		last, err := r.watchOnce(ctx, since, oom, onExit)
		if !last.IsZero() {
			since = last
		}
		if ctx.Err() != nil {
			return
		}
		slog.Warn("docker event stream ended, reconnecting", "error", err)
		select {
		case <-ctx.Done():
			return
		case <-time.After(2 * time.Second):
		}
	}
}

func (r *Runner) watchOnce(ctx context.Context, since time.Time, oom map[string]bool, onExit func(Exit)) (time.Time, error) {
	filters, _ := json.Marshal(map[string][]string{
		"type":  {"container"},
		"event": {"die", "oom"},
		"label": {LabelHost + "=" + r.HostID},
	})
	q := queryOf("filters", string(filters), "since", strconv.FormatInt(since.Unix(), 10))
	resp, err := r.Docker.request(ctx, "GET", "/events", q, nil)
	if err != nil {
		return time.Time{}, err
	}
	defer resp.Body.Close()

	var last time.Time
	dec := json.NewDecoder(resp.Body)
	for {
		var ev struct {
			Action string `json:"Action"`
			Time   int64  `json:"time"`
			Actor  struct {
				ID         string            `json:"ID"`
				Attributes map[string]string `json:"Attributes"`
			} `json:"Actor"`
		}
		if err := dec.Decode(&ev); err != nil {
			if errors.Is(err, io.EOF) {
				return last, nil
			}
			return last, err
		}
		last = time.Unix(ev.Time, 0)

		runnerID := ev.Actor.Attributes[LabelRunner]
		switch ev.Action {
		case "oom":
			oom[ev.Actor.ID] = true
		case "die":
			code, _ := strconv.Atoi(ev.Actor.Attributes["exitCode"])
			exit := Exit{RunnerID: runnerID, ExitCode: code, OOM: oom[ev.Actor.ID]}
			delete(oom, ev.Actor.ID)

			cleanup, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
			if err := r.Docker.RemoveNetwork(cleanup, networkName(runnerID)); err != nil {
				slog.Warn("could not remove runner network", "runner", runnerID, "error", err)
			}
			cancel()
			onExit(exit)
		}
	}
}
