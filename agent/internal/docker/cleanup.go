package docker

import (
	"context"
	"fmt"
	"net/url"
	"time"
)

// Known is the set of runner ids the control plane still has.
type Known map[string]bool

// RemoveOrphans deletes this host's Jogger containers (runners and sidecars) whose runner the server no longer
// knows and which are older than minAge — the age guard keeps a just-started runner, whose registration may not
// have reached the server yet, safe. It returns the runner ids it cleaned up.
func (r *Runner) RemoveOrphans(ctx context.Context, known Known, minAge time.Duration, now time.Time) ([]string, error) {
	list, err := r.Docker.Containers(ctx, map[string][]string{"label": {LabelHost + "=" + r.HostID}}, true)
	if err != nil {
		return nil, err
	}

	var removed []string
	for _, c := range list {
		id := c.Labels[LabelRunner]
		if id == "" {
			id = c.Labels[LabelSidecar]
		}
		if id == "" || known[id] || now.Sub(time.Unix(c.Created, 0)) < minAge {
			continue
		}
		q := url.Values{"force": {"true"}, "v": {"true"}}
		if err := r.Docker.do(ctx, "DELETE", "/containers/"+c.ID, q, nil, nil); err != nil && !IsNotFound(err) {
			return removed, fmt.Errorf("removing orphan %s: %w", c.ID, err)
		}
		if err := r.teardown(ctx, id); err != nil {
			return removed, err
		}
		removed = append(removed, id)
	}

	return removed, nil
}

// PruneResult reports what host.prune reclaimed.
type PruneResult struct {
	ImagesDeleted int   `json:"images_deleted"`
	BytesFreed    int64 `json:"bytes_freed"`
}

// Prune removes dangling images and this host's unused Jogger volumes. It never touches anything unlabelled
// beyond dangling images, so a machine shared with other workloads keeps its own data.
func (r *Runner) Prune(ctx context.Context) (PruneResult, error) {
	var res PruneResult
	var images struct {
		ImagesDeleted  []map[string]string `json:"ImagesDeleted"`
		SpaceReclaimed int64               `json:"SpaceReclaimed"`
	}
	if err := r.Docker.do(ctx, "POST", "/images/prune", url.Values{"filters": {`{"dangling":["true"]}`}}, nil, &images); err != nil {
		return res, fmt.Errorf("pruning images: %w", err)
	}
	res.ImagesDeleted, res.BytesFreed = len(images.ImagesDeleted), images.SpaceReclaimed

	var volumes struct {
		SpaceReclaimed int64 `json:"SpaceReclaimed"`
	}
	filter := fmt.Sprintf(`{"label":[%q]}`, LabelHost+"="+r.HostID)
	if err := r.Docker.do(ctx, "POST", "/volumes/prune", url.Values{"filters": {filter}}, nil, &volumes); err != nil {
		return res, fmt.Errorf("pruning volumes: %w", err)
	}
	res.BytesFreed += volumes.SpaceReclaimed

	return res, nil
}
