package docker

import (
	"context"
	"fmt"
	"os"
	"sync"
	"testing"
	"time"
)

const testImage = "alpine:latest"

// integration returns a runner against the local engine, skipping when there is none (or no alpine image,
// so the suite never needs the network).
func integration(t *testing.T) *Runner {
	t.Helper()
	socket := os.Getenv("DOCKER_SOCKET")
	if socket == "" {
		socket = "/var/run/docker.sock"
	}
	c := New(socket)
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	if ok, err := c.HasImage(ctx, testImage); err != nil || !ok {
		t.Skipf("needs a local Docker engine with %s (err=%v)", testImage, err)
	}

	return &Runner{Docker: c, HostID: "test-host-" + fmt.Sprint(os.Getpid())}
}

func spec(id string, cmd ...string) Spec {
	return Spec{RunnerID: id, Name: "r-" + id, Image: testImage, JITConfig: "secret-jit", Command: cmd, CPU: 0.5, MemoryMB: 64}
}

func inspect(t *testing.T, r *Runner, id string) map[string]any {
	t.Helper()
	var out map[string]any
	if err := r.Docker.do(context.Background(), "GET", "/containers/"+id+"/json", nil, nil, &out); err != nil {
		t.Fatal(err)
	}

	return out
}

func cleanup(t *testing.T, r *Runner, id string) {
	t.Cleanup(func() { _, _ = r.Stop(context.Background(), id, time.Second) })
}

func TestStartAppliesLimitsAndHardening(t *testing.T) {
	r := integration(t)
	id := fmt.Sprintf("hard-%d", time.Now().UnixNano())
	cleanup(t, r, id)

	started, err := r.Start(context.Background(), spec(id, "sleep", "60"))
	if err != nil || started.Existing {
		t.Fatalf("start: %+v %v", started, err)
	}

	info := inspect(t, r, started.ContainerID)
	host := info["HostConfig"].(map[string]any)
	if host["NanoCpus"].(float64) != 0.5e9 {
		t.Errorf("NanoCpus = %v", host["NanoCpus"])
	}
	if host["Memory"].(float64) != 64<<20 || host["MemorySwap"].(float64) != 64<<20 {
		t.Errorf("memory = %v swap = %v", host["Memory"], host["MemorySwap"])
	}
	if host["PidsLimit"].(float64) != pidsLimit {
		t.Errorf("PidsLimit = %v", host["PidsLimit"])
	}
	if fmt.Sprint(host["SecurityOpt"]) != "[no-new-privileges]" || fmt.Sprint(host["CapDrop"]) != "[ALL]" {
		t.Errorf("security = %v / %v", host["SecurityOpt"], host["CapDrop"])
	}
	if host["AutoRemove"] != true || host["Privileged"] == true {
		t.Errorf("AutoRemove=%v Privileged=%v", host["AutoRemove"], host["Privileged"])
	}
	if host["NetworkMode"] != networkName(id) {
		t.Errorf("NetworkMode = %v", host["NetworkMode"])
	}
	labels := info["Config"].(map[string]any)["Labels"].(map[string]any)
	if labels[LabelRunner] != id || labels[LabelHost] != r.HostID {
		t.Errorf("labels = %v", labels)
	}
	env := fmt.Sprint(info["Config"].(map[string]any)["Env"])
	if !contains(env, "JIT_CONFIG=secret-jit") {
		t.Errorf("env lacks JIT_CONFIG: %s", env)
	}
}

func contains(s, sub string) bool {
	return len(s) >= len(sub) && (func() bool { return indexOf(s, sub) >= 0 })()
}

func indexOf(s, sub string) int {
	for i := 0; i+len(sub) <= len(s); i++ {
		if s[i:i+len(sub)] == sub {
			return i
		}
	}
	return -1
}

func TestStartIsIdempotent(t *testing.T) {
	r := integration(t)
	id := fmt.Sprintf("idem-%d", time.Now().UnixNano())
	cleanup(t, r, id)

	first, err := r.Start(context.Background(), spec(id, "sleep", "60"))
	if err != nil {
		t.Fatal(err)
	}
	second, err := r.Start(context.Background(), spec(id, "sleep", "60"))
	if err != nil {
		t.Fatal(err)
	}
	if !second.Existing || second.ContainerID != first.ContainerID {
		t.Fatalf("second start = %+v, first = %+v", second, first)
	}
	list, _ := r.Docker.Containers(context.Background(), map[string][]string{"label": {LabelRunner + "=" + id}}, true)
	if len(list) != 1 {
		t.Fatalf("%d containers for one runner", len(list))
	}
}

func TestStopRemovesContainerAndNetwork(t *testing.T) {
	r := integration(t)
	id := fmt.Sprintf("stop-%d", time.Now().UnixNano())
	if _, err := r.Start(context.Background(), spec(id, "sleep", "60")); err != nil {
		t.Fatal(err)
	}

	stopped, err := r.Stop(context.Background(), id, time.Second)
	if err != nil || !stopped {
		t.Fatalf("stop: %v %v", stopped, err)
	}
	list, _ := r.Docker.Containers(context.Background(), map[string][]string{"label": {LabelRunner + "=" + id}}, true)
	if len(list) != 0 {
		t.Fatalf("container survived: %+v", list)
	}
	if err := r.Docker.do(context.Background(), "GET", "/networks/"+networkName(id), nil, nil, nil); !IsNotFound(err) {
		t.Fatalf("network survived: %v", err)
	}

	again, err := r.Stop(context.Background(), id, time.Second)
	if err != nil || again {
		t.Fatalf("stopping a missing runner should succeed quietly: %v %v", again, err)
	}
}

func watch(t *testing.T, r *Runner) (<-chan Exit, context.CancelFunc) {
	ctx, cancel := context.WithCancel(context.Background())
	ch := make(chan Exit, 8)
	var mu sync.Mutex
	go r.Watch(ctx, func(e Exit) { mu.Lock(); ch <- e; mu.Unlock() })
	time.Sleep(300 * time.Millisecond) // let the stream attach
	t.Cleanup(cancel)

	return ch, cancel
}

func wait(t *testing.T, ch <-chan Exit, id string) Exit {
	t.Helper()
	timeout := time.After(30 * time.Second)
	for {
		select {
		case e := <-ch:
			if e.RunnerID == id {
				return e
			}
		case <-timeout:
			t.Fatal("no exit event")
		}
	}
}

func TestExitCodeIsPropagatedAndNetworkCleaned(t *testing.T) {
	r := integration(t)
	exits, _ := watch(t, r)
	id := fmt.Sprintf("exit-%d", time.Now().UnixNano())
	cleanup(t, r, id)

	if _, err := r.Start(context.Background(), spec(id, "sh", "-c", "exit 7")); err != nil {
		t.Fatal(err)
	}
	e := wait(t, exits, id)
	if e.ExitCode != 7 || e.OOM {
		t.Fatalf("exit = %+v", e)
	}
	if err := r.Docker.do(context.Background(), "GET", "/networks/"+networkName(id), nil, nil, nil); !IsNotFound(err) {
		t.Fatalf("network not cleaned after exit: %v", err)
	}
}

func TestOOMKillIsReported(t *testing.T) {
	r := integration(t)
	exits, _ := watch(t, r)
	id := fmt.Sprintf("oom-%d", time.Now().UnixNano())
	cleanup(t, r, id)

	s := spec(id, "sh", "-c", "x=$(head -c 300000000 /dev/zero | tr '\\0' a); echo ${#x}")
	s.MemoryMB = 16
	if _, err := r.Start(context.Background(), s); err != nil {
		t.Fatal(err)
	}
	e := wait(t, exits, id)
	if !e.OOM || e.ExitCode == 0 {
		t.Fatalf("exit = %+v", e)
	}
}

func TestSpecValidation(t *testing.T) {
	for name, s := range map[string]Spec{
		"no id":      {Image: "x"},
		"bad id":     {RunnerID: "a/../b", Image: "x"},
		"no image":   {RunnerID: "a"},
		"dind":       {RunnerID: "a", Image: "x", DockerMode: "dind"},
		"neg memory": {RunnerID: "a", Image: "x", MemoryMB: -1},
	} {
		if s.Validate() == nil {
			t.Errorf("%s: expected an error", name)
		}
	}
	if err := (Spec{RunnerID: "a-1", Image: "x", DockerMode: "none"}).Validate(); err != nil {
		t.Errorf("valid spec rejected: %v", err)
	}
}

func TestSplitRef(t *testing.T) {
	for in, want := range map[string][2]string{
		"alpine":                {"alpine", "latest"},
		"alpine:3.20":           {"alpine", "3.20"},
		"localhost:5000/runner": {"localhost:5000/runner", "latest"},
		"ghcr.io/a/b:2.1":       {"ghcr.io/a/b", "2.1"},
		"a/b@sha256:abc":        {"a/b", "sha256:abc"},
	} {
		if i, tag := splitRef(in); i != want[0] || tag != want[1] {
			t.Errorf("%s -> %s %s", in, i, tag)
		}
	}
}
