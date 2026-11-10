package telemetry

import (
	"context"
	"errors"
	"net"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"
)

func TestParseMeminfo(t *testing.T) {
	total, used, ok := ParseMeminfo(strings.NewReader("MemTotal:       16384000 kB\nMemFree:         1000000 kB\nMemAvailable:    8192000 kB\n"))
	if !ok || total != 16000 || used != 8000 {
		t.Errorf("total=%d used=%d ok=%v", total, used, ok)
	}
	if _, _, ok := ParseMeminfo(strings.NewReader("MemTotal: 100 kB\n")); ok {
		t.Error("without MemAvailable the figure is unknown")
	}
	if _, _, ok := ParseMeminfo(strings.NewReader("")); ok {
		t.Error("empty input must not parse")
	}
}

func TestParseCPUStat(t *testing.T) {
	// user nice system idle iowait irq softirq steal
	busy, total, ok := ParseCPUStat(strings.NewReader("cpu  100 0 50 800 50 0 0 0 0 0\ncpu0 1 1 1 1 1\n"))
	if !ok || total != 1000 || busy != 150 {
		t.Errorf("busy=%d total=%d ok=%v", busy, total, ok)
	}
	if _, _, ok := ParseCPUStat(strings.NewReader("intr 1 2 3\n")); ok {
		t.Error("no cpu line, no result")
	}
	if _, _, ok := ParseCPUStat(strings.NewReader("cpu  a b c d\n")); ok {
		t.Error("garbage must not parse")
	}
}

func TestNormalizeArch(t *testing.T) {
	if NormalizeArch("amd64") != "x64" || NormalizeArch("arm64") != "arm64" {
		t.Error("architecture names must match GitHub runner naming")
	}
}

func TestCollectUsesProcFilesAndComputesCPUFromDeltas(t *testing.T) {
	proc := t.TempDir()
	os.WriteFile(filepath.Join(proc, "meminfo"), []byte("MemTotal: 2097152 kB\nMemAvailable: 1048576 kB\n"), 0o644)
	stat := func(busyJiffies, idleJiffies int) {
		os.WriteFile(filepath.Join(proc, "stat"), []byte("cpu  "+strconv.Itoa(busyJiffies)+" 0 0 "+strconv.Itoa(idleJiffies)+" 0 0 0 0\n"), 0o644)
	}
	c := &Collector{ProcDir: proc, DiskPath: t.TempDir(), DockerSocket: filepath.Join(proc, "none.sock")}

	stat(100, 900)
	first := c.Collect(context.Background())
	if first.CPUUsed != nil {
		t.Error("cpu usage needs two samples")
	}
	if first.MemoryTotalMB == nil || *first.MemoryTotalMB != 2048 || *first.MemoryUsedMB != 1024 {
		t.Errorf("memory: %+v", first)
	}
	if first.DiskFreeGB == nil {
		t.Error("disk free should be measurable on a temp dir")
	}
	if first.DockerVersion != "" || first.AgentVersion == "" || first.Arch == "" || first.CPUTotal < 1 {
		t.Errorf("facts: %+v", first)
	}

	stat(600, 1400) // +500 busy, +500 idle: half of all cores busy
	second := c.Collect(context.Background())
	if second.CPUUsed == nil || *second.CPUUsed < second.CPUTotal/2-0.01 || *second.CPUUsed > second.CPUTotal/2+0.01 {
		t.Errorf("cpu used = %v of %v", second.CPUUsed, second.CPUTotal)
	}
}

func TestMissingProcFilesDoNotBreakCollection(t *testing.T) {
	c := &Collector{ProcDir: "/definitely/not/here", DiskPath: "/definitely/not/here", DockerSocket: "/nope.sock"}
	f := c.Collect(context.Background())
	if f.MemoryTotalMB != nil || f.CPUUsed != nil || f.DiskFreeGB != nil || f.CPUTotal < 1 {
		t.Errorf("unmeasurable fields must be left empty: %+v", f)
	}
}

func TestDockerVersionOverASocket(t *testing.T) {
	sock := filepath.Join(t.TempDir(), "docker.sock")
	l, err := net.Listen("unix", sock)
	if err != nil {
		t.Skip("unix sockets unavailable:", err)
	}
	srv := &http.Server{Handler: http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/version" {
			w.WriteHeader(404)
			return
		}
		w.Write([]byte(`{"Version":"27.3.1","ApiVersion":"1.47"}`))
	})}
	go srv.Serve(l)
	defer srv.Close()

	v, err := DockerVersion(context.Background(), sock)
	if err != nil || v != "27.3.1" {
		t.Errorf("v=%q err=%v", v, err)
	}
	if _, err := DockerVersion(context.Background(), filepath.Join(t.TempDir(), "missing.sock")); err == nil {
		t.Error("a missing socket must be an error, not a panic")
	}
}

func TestEnrollmentFactsOmitUnknowns(t *testing.T) {
	m := Facts{Arch: "x64", OS: "linux", AgentVersion: "1", CPUTotal: 2}.Enrollment()
	if _, has := m["memory_total_mb"]; has {
		t.Error("unknown memory must be omitted")
	}
	if m["arch"] != "x64" || m["cpu_total"] != 2.0 {
		t.Errorf("%v", m)
	}
}

func noSleep(_ context.Context, _ time.Duration) error { return nil }

func TestLoopReportsEveryIntervalAndBacksOffOnFailure(t *testing.T) {
	var waits []time.Duration
	var sent int
	ctx, cancel := context.WithCancel(context.Background())
	outcomes := []error{nil, errors.New("down"), errors.New("down"), nil, nil}

	err := Loop(ctx, 10*time.Second,
		func(context.Context) Facts { return Facts{} },
		func(context.Context, Facts) error {
			e := outcomes[sent]
			sent++
			if sent == len(outcomes) {
				cancel()
			}

			return e
		},
		func(attempt int) time.Duration { return time.Duration(1<<attempt) * time.Second },
		func(error) bool { return true },
		func(ctx context.Context, d time.Duration) error { waits = append(waits, d); return ctx.Err() },
		nil,
	)
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("err = %v", err)
	}
	// ok → interval; fail → 1 s; fail → 2 s; ok → interval (failure counter reset); last is cancelled.
	want := []time.Duration{10 * time.Second, time.Second, 2 * time.Second, 10 * time.Second}
	for i, w := range want {
		if waits[i] != w {
			t.Errorf("wait %d = %v, want %v (all: %v)", i, waits[i], w, waits)
		}
	}
}

func TestLoopBackoffNeverExceedsSixIntervals(t *testing.T) {
	var waits []time.Duration
	n := 0
	Loop(context.Background(), time.Second,
		func(context.Context) Facts { return Facts{} },
		func(context.Context, Facts) error {
			n++
			if n > 3 {
				return context.Canceled
			}
			return errors.New("down")
		},
		func(int) time.Duration { return time.Hour },
		func(err error) bool { return !errors.Is(err, context.Canceled) },
		func(_ context.Context, d time.Duration) error { waits = append(waits, d); return nil },
		nil,
	)
	for _, w := range waits {
		if w > 6*time.Second {
			t.Errorf("wait %v exceeds the cap", w)
		}
	}
}

func TestLoopStopsOnPermanentError(t *testing.T) {
	perm := errors.New("revoked")
	n := 0
	err := Loop(context.Background(), time.Second,
		func(context.Context) Facts { return Facts{} },
		func(context.Context, Facts) error { n++; return perm },
		func(int) time.Duration { return 0 },
		func(error) bool { return false },
		noSleep, nil)
	if err != perm || n != 1 {
		t.Errorf("err=%v n=%d", err, n)
	}
}
