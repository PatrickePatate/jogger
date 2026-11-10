package docker

import (
	"context"
	"fmt"
	"net/url"
	"strconv"
)

// LabelToolCache marks a pool's persistent tool-cache volume. It deliberately carries no host label: volumes
// labelled with the host are per-runner leftovers that Prune removes, and this one must outlive its runners.
const LabelToolCache = "jogger.tool-cache"

// toolCacheDir is where actions/setup-* keep downloaded toolchains (AGENT_TOOLSDIRECTORY in the runner image).
const toolCacheDir = "/opt/hostedtoolcache"

// toolCacheMount is the mount for a pool's tool cache, or nil when the pool does not persist one.
func toolCacheMount(s Spec) map[string]any {
	if s.ToolCacheVolume == "" {
		return nil
	}

	return map[string]any{"Type": "volume", "Source": s.ToolCacheVolume, "Target": toolCacheDir}
}

// ensureToolCache creates the pool's volume when it is missing; creating an existing volume changes nothing.
func (r *Runner) ensureToolCache(ctx context.Context, s Spec) error {
	if s.ToolCacheVolume == "" {
		return nil
	}
	body := map[string]any{"Name": s.ToolCacheVolume, "Labels": map[string]string{LabelToolCache: "1"}}
	if err := r.Docker.do(ctx, "POST", "/volumes/create", nil, body, nil); err != nil {
		return fmt.Errorf("creating tool cache volume: %w", err)
	}

	return nil
}

// buildPruneQuery asks Docker to delete build cache until at most maxGB remain; 0 or less means "leave it".
func buildPruneQuery(maxGB float64) url.Values {
	if maxGB <= 0 {
		return nil
	}

	return url.Values{"keep-storage": {strconv.FormatInt(int64(maxGB*float64(1<<30)), 10)}}
}

// PruneBuildCache trims the engine's build cache to the cap and reports the bytes freed.
func (r *Runner) PruneBuildCache(ctx context.Context, maxGB float64) (int64, error) {
	query := buildPruneQuery(maxGB)
	if query == nil {
		return 0, nil
	}
	var out struct {
		SpaceReclaimed int64 `json:"SpaceReclaimed"`
	}
	if err := r.Docker.do(ctx, "POST", "/build/prune", query, nil, &out); err != nil {
		return 0, fmt.Errorf("pruning build cache: %w", err)
	}

	return out.SpaceReclaimed, nil
}
