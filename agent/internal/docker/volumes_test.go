package docker

import (
	"context"
	"testing"
)

func TestToolCacheMountOnlyWhenRequested(t *testing.T) {
	if m := toolCacheMount(Spec{RunnerID: "a", Image: "runner"}); m != nil {
		t.Errorf("no volume was asked for, got %v", m)
	}
	m := toolCacheMount(Spec{RunnerID: "a", Image: "runner", ToolCacheVolume: "jogger-toolcache-pool1"})
	if m["Source"] != "jogger-toolcache-pool1" || m["Target"] != "/opt/hostedtoolcache" || m["Type"] != "volume" {
		t.Errorf("unexpected mount %v", m)
	}
}

func TestToolCacheVolumesDifferPerPool(t *testing.T) {
	a := toolCacheMount(Spec{ToolCacheVolume: "jogger-toolcache-pool1"})
	b := toolCacheMount(Spec{ToolCacheVolume: "jogger-toolcache-pool2"})
	if a["Source"] == b["Source"] {
		t.Error("two pools must never share a tool cache volume")
	}
}

func TestToolCacheVolumeNameIsValidated(t *testing.T) {
	for _, name := range []string{"/var/run/docker.sock", "../etc", "other-volume", "jogger-toolcache-"} {
		if err := (Spec{RunnerID: "a", Image: "runner", ToolCacheVolume: name}).Validate(); err == nil {
			t.Errorf("%q should be rejected", name)
		}
	}
	if err := (Spec{RunnerID: "a", Image: "runner", ToolCacheVolume: "jogger-toolcache-0193-abc"}).Validate(); err != nil {
		t.Errorf("valid name rejected: %v", err)
	}
}

func TestBuildPruneQueryHonoursTheCap(t *testing.T) {
	if q := buildPruneQuery(0); q != nil {
		t.Errorf("no cap means no pruning, got %v", q)
	}
	if got := buildPruneQuery(2).Get("keep-storage"); got != "2147483648" {
		t.Errorf("keep-storage = %s", got)
	}
}

// With a real engine: the volume survives the runner and Prune, and is the same one for the next runner.
func TestToolCachePersistsAcrossRunners(t *testing.T) {
	r := integration(t)
	ctx := context.Background()
	const volume = "jogger-toolcache-agenttest"
	t.Cleanup(func() { _ = r.Docker.do(ctx, "DELETE", "/volumes/"+volume, nil, nil, nil) })

	for _, id := range []string{"tc-one", "tc-two"} {
		s := Spec{RunnerID: id, Image: testImage, ToolCacheVolume: volume, Command: []string{"sleep", "30"}}
		if _, err := r.Start(ctx, s); err != nil {
			t.Fatal(err)
		}
		if _, err := r.Prune(ctx); err != nil { // in use: must not be touched
			t.Fatal(err)
		}
		if _, err := r.Stop(ctx, id, 0); err != nil {
			t.Fatal(err)
		}
		if _, err := r.Prune(ctx); err != nil { // unused, but not a per-runner leftover: must stay
			t.Fatal(err)
		}
		if err := r.Docker.do(ctx, "GET", "/volumes/"+volume, nil, nil, nil); err != nil {
			t.Fatalf("volume gone after runner %s: %v", id, err)
		}
	}
}
