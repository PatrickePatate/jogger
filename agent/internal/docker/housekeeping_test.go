package docker

import (
	"context"
	"fmt"
	"strings"
	"testing"
	"time"
)

func TestOrphanRemovalSparesKnownAndYoungRunners(t *testing.T) {
	r := integration(t)
	ctx := context.Background()
	stamp := time.Now().UnixNano()
	orphan, known, young := fmt.Sprintf("orph-%d", stamp), fmt.Sprintf("known-%d", stamp), fmt.Sprintf("young-%d", stamp)
	for _, id := range []string{orphan, known, young} {
		cleanup(t, r, id)
		if _, err := r.Start(ctx, spec(id, "sleep", "60")); err != nil {
			t.Fatal(err)
		}
	}

	// "Now" is 20 minutes ahead, so every container counts as older than the 10-minute guard...
	later := time.Now().Add(20 * time.Minute)
	removed, err := r.RemoveOrphans(ctx, Known{known: true}, 10*time.Minute, later)
	if err != nil {
		t.Fatal(err)
	}
	if !contains(strings.Join(removed, ","), orphan) || !contains(strings.Join(removed, ","), young) || contains(strings.Join(removed, ","), known) {
		t.Fatalf("removed = %v", removed)
	}
	if id, _ := r.find(ctx, known); id == "" {
		t.Fatal("a running managed container the server knows was removed")
	}
	if id, _ := r.find(ctx, orphan); id != "" {
		t.Fatal("orphan survived")
	}
	if err := r.Docker.do(ctx, "GET", "/networks/"+networkName(orphan), nil, nil, nil); !IsNotFound(err) {
		t.Fatalf("orphan's network survived: %v", err)
	}

	// ...while with the real clock, a fresh unknown container is left alone.
	fresh := fmt.Sprintf("fresh-%d", stamp)
	cleanup(t, r, fresh)
	if _, err := r.Start(ctx, spec(fresh, "sleep", "60")); err != nil {
		t.Fatal(err)
	}
	removed, _ = r.RemoveOrphans(ctx, Known{known: true}, 10*time.Minute, time.Now())
	if contains(strings.Join(removed, ","), fresh) {
		t.Fatal("a runner younger than the age guard was removed")
	}
}

func TestPruneIsScopedAndSucceeds(t *testing.T) {
	r := integration(t)
	if _, err := r.Prune(context.Background()); err != nil {
		t.Fatal(err)
	}
}

func TestDindSidecarBodyIsolatesAndSecures(t *testing.T) {
	s := Spec{RunnerID: "abc", Image: "runner", DockerMode: "dind", CPU: 2, MemoryMB: 1024}
	body := sidecarBody(s, DefaultDindImage, networkName("abc"), sidecarLabels(s, "h1"))

	if body["Image"] != DefaultDindImage {
		t.Errorf("image = %v", body["Image"])
	}
	host := body["HostConfig"].(map[string]any)
	if host["NetworkMode"] != "jogger-net-abc" {
		t.Errorf("sidecar must sit on the runner's private network, got %v", host["NetworkMode"])
	}
	for _, m := range host["Mounts"].([]map[string]any) {
		if m["Type"] != "volume" || strings.Contains(fmt.Sprint(m["Source"]), "docker.sock") {
			t.Errorf("unexpected mount %v", m)
		}
	}
	if strings.Contains(fmt.Sprint(body), "docker.sock") || host["Binds"] != nil {
		t.Error("the host socket must never be mounted")
	}
	if fmt.Sprint(body["Env"]) != "[DOCKER_TLS_CERTDIR=/certs]" {
		t.Errorf("TLS must be on: %v", body["Env"])
	}
	aliases := body["NetworkingConfig"].(map[string]any)["EndpointsConfig"].(map[string]any)[networkName("abc")].(map[string]any)["Aliases"]
	if fmt.Sprint(aliases) != "[docker]" {
		t.Errorf("aliases = %v", aliases)
	}
	if body["Labels"].(map[string]string)[LabelSidecar] != "abc" || body["Labels"].(map[string]string)[LabelRunner] != "" {
		t.Errorf("labels = %v", body["Labels"])
	}
}

// Needs the dind-rootless image locally and a kernel that allows it; it is the only test that proves a job can
// really reach its private daemon, so it runs wherever the prerequisites exist.
func TestDindRunnerGetsPrivateTLSDaemon(t *testing.T) {
	r := integration(t)
	ctx := context.Background()
	if ok, _ := r.Docker.HasImage(ctx, DefaultDindImage); !ok {
		t.Skipf("needs %s locally", DefaultDindImage)
	}
	id := fmt.Sprintf("dind-%d", time.Now().UnixNano())
	cleanup(t, r, id)

	s := spec(id, "sleep", "60")
	s.DockerMode, s.MemoryMB = "dind", 0
	started, err := r.Start(ctx, s)
	if err != nil {
		t.Fatal(err)
	}

	info := inspect(t, r, started.ContainerID)
	env := fmt.Sprint(info["Config"].(map[string]any)["Env"])
	for _, want := range []string{"DOCKER_HOST=tcp://localhost:2376", "DOCKER_TLS_VERIFY=1", "DOCKER_CERT_PATH=/certs/client"} {
		if !strings.Contains(env, want) {
			t.Errorf("runner env lacks %s", want)
		}
	}
	if strings.Contains(fmt.Sprint(info["HostConfig"].(map[string]any)["Binds"]), "docker.sock") {
		t.Error("host socket mounted in the runner")
	}
	side, _ := r.Docker.Containers(ctx, map[string][]string{"label": {LabelSidecar + "=" + id}}, true)
	if len(side) != 1 {
		t.Fatalf("expected one sidecar, got %d", len(side))
	}
	sideInfo := inspect(t, r, side[0].ID)
	if strings.Contains(fmt.Sprint(sideInfo["HostConfig"]), "docker.sock") {
		t.Error("host socket mounted in the sidecar")
	}

	if _, err := r.Stop(ctx, id, time.Second); err != nil {
		t.Fatal(err)
	}
	if left, _ := r.Docker.Containers(ctx, map[string][]string{"label": {LabelSidecar + "=" + id}}, true); len(left) != 0 {
		t.Fatal("sidecar survived stop")
	}
}
