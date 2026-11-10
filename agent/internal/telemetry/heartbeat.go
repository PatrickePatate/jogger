// Package telemetry collects the host's capacity facts and reports them on a schedule.
package telemetry

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"runtime"
	"strconv"
	"strings"
	"syscall"
	"time"
)

// Version is the agent version; overridden at build time with -ldflags "-X .../telemetry.Version=…".
var Version = "0.1.0-dev"

// Facts is one capacity report. Pointer fields are omitted when they could not be measured.
type Facts struct {
	Arch          string   `json:"arch"`
	OS            string   `json:"os"`
	DockerVersion string   `json:"docker_version,omitempty"`
	AgentVersion  string   `json:"agent_version"`
	CPUTotal      float64  `json:"cpu_total"`
	CPUUsed       *float64 `json:"cpu_used,omitempty"`
	MemoryTotalMB *int64   `json:"memory_total_mb,omitempty"`
	MemoryUsedMB  *int64   `json:"memory_used_mb,omitempty"`
	DiskFreeGB    *int64   `json:"disk_free_gb,omitempty"`
}

// Enrollment is the subset sent when enrolling (no "used" figures).
func (f Facts) Enrollment() map[string]any {
	out := map[string]any{"arch": f.Arch, "os": f.OS, "agent_version": f.AgentVersion, "cpu_total": f.CPUTotal}
	if f.DockerVersion != "" {
		out["docker_version"] = f.DockerVersion
	}
	if f.MemoryTotalMB != nil {
		out["memory_total_mb"] = *f.MemoryTotalMB
	}
	if f.DiskFreeGB != nil {
		out["disk_free_gb"] = *f.DiskFreeGB
	}

	return out
}

// Collector gathers facts. Paths and the Docker socket are fields so tests can point them at fixtures.
type Collector struct {
	ProcDir      string // usually /proc
	DiskPath     string // filesystem whose free space is reported
	DockerSocket string

	prevBusy, prevTotal uint64
	havePrev            bool
}

func NewCollector(diskPath, dockerSocket string) *Collector {
	return &Collector{ProcDir: "/proc", DiskPath: diskPath, DockerSocket: dockerSocket}
}

// Collect measures what it can; a failing probe leaves its field empty rather than failing the heartbeat.
func (c *Collector) Collect(ctx context.Context) Facts {
	f := Facts{Arch: NormalizeArch(runtime.GOARCH), OS: runtime.GOOS, AgentVersion: Version, CPUTotal: float64(runtime.NumCPU())}

	if file, err := os.Open(c.ProcDir + "/meminfo"); err == nil {
		if total, used, ok := ParseMeminfo(file); ok {
			f.MemoryTotalMB, f.MemoryUsedMB = &total, &used
		}
		file.Close()
	}

	if file, err := os.Open(c.ProcDir + "/stat"); err == nil {
		if busy, total, ok := ParseCPUStat(file); ok {
			if c.havePrev && total > c.prevTotal {
				used := float64(busy-c.prevBusy) / float64(total-c.prevTotal) * f.CPUTotal
				f.CPUUsed = &used
			}
			c.prevBusy, c.prevTotal, c.havePrev = busy, total, true
		}
		file.Close()
	}

	var st syscall.Statfs_t
	if err := syscall.Statfs(c.DiskPath, &st); err == nil {
		free := int64(st.Bavail) * int64(st.Bsize) / (1 << 30)
		f.DiskFreeGB = &free
	}

	if v, err := DockerVersion(ctx, c.DockerSocket); err == nil {
		f.DockerVersion = v
	}

	return f
}

// NormalizeArch maps Go's architecture names to the ones GitHub runners use.
func NormalizeArch(goarch string) string {
	switch goarch {
	case "amd64":
		return "x64"
	default:
		return goarch // arm64 already matches
	}
}

// ParseMeminfo returns total and used memory in MB, where "used" excludes reclaimable cache (MemAvailable).
func ParseMeminfo(r io.Reader) (totalMB, usedMB int64, ok bool) {
	var total, available int64 = -1, -1
	scanner := bufio.NewScanner(r)
	for scanner.Scan() {
		fields := strings.Fields(scanner.Text())
		if len(fields) < 2 {
			continue
		}
		kb, err := strconv.ParseInt(fields[1], 10, 64)
		if err != nil {
			continue
		}
		switch fields[0] {
		case "MemTotal:":
			total = kb
		case "MemAvailable:":
			available = kb
		}
	}
	if total <= 0 || available < 0 {
		return 0, 0, false
	}

	return total / 1024, (total - available) / 1024, true
}

// ParseCPUStat reads the aggregate "cpu" line of /proc/stat and returns busy and total jiffies.
func ParseCPUStat(r io.Reader) (busy, total uint64, ok bool) {
	scanner := bufio.NewScanner(r)
	for scanner.Scan() {
		fields := strings.Fields(scanner.Text())
		if len(fields) < 5 || fields[0] != "cpu" {
			continue
		}
		var values []uint64
		for _, f := range fields[1:] {
			n, err := strconv.ParseUint(f, 10, 64)
			if err != nil {
				return 0, 0, false
			}
			values = append(values, n)
		}
		for _, v := range values {
			total += v
		}
		idle := values[3]
		if len(values) > 4 {
			idle += values[4] // iowait counts as idle
		}

		return total - idle, total, true
	}

	return 0, 0, false
}

// DockerVersion asks the Docker engine for its version over the unix socket (no SDK needed for this).
func DockerVersion(ctx context.Context, socket string) (string, error) {
	client := &http.Client{
		Timeout: 3 * time.Second,
		Transport: &http.Transport{DialContext: func(ctx context.Context, _, _ string) (net.Conn, error) {
			return (&net.Dialer{}).DialContext(ctx, "unix", socket)
		}},
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, "http://docker/version", nil)
	if err != nil {
		return "", err
	}
	resp, err := client.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return "", fmt.Errorf("docker returned %d", resp.StatusCode)
	}
	var v struct {
		Version string `json:"Version"`
	}
	if err := json.NewDecoder(resp.Body).Decode(&v); err != nil {
		return "", err
	}

	return v.Version, nil
}

// Sender delivers one report (an HTTP POST in production).
type Sender func(ctx context.Context, f Facts) error

// Loop reports every interval until ctx ends. A failed report is retried after a backoff delay (never longer than
// 60 s) instead of waiting a whole interval; revocation or any other permanent error stops the loop.
func Loop(ctx context.Context, interval time.Duration, collect func(context.Context) Facts, send Sender,
	delay func(attempt int) time.Duration, retryable func(error) bool, sleep func(context.Context, time.Duration) error, onError func(error)) error {
	failures := 0
	for {
		err := send(ctx, collect(ctx))
		wait := interval
		switch {
		case err == nil:
			failures = 0
		case !retryable(err):
			return err
		default:
			if onError != nil {
				onError(err)
			}
			wait = min(delay(failures), interval*6)
			failures++
		}
		if serr := sleep(ctx, wait); serr != nil {
			return serr
		}
	}
}
