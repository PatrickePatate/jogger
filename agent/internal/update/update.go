// Package update checks whether the control plane advertises a newer agent and, only when explicitly enabled,
// replaces the running binary with it.
package update

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"sync"

	"github.com/PatrickePatate/jogger/agent/internal/transport"
)

// Info is the answer of GET /agent/v1/version.
type Info struct {
	Latest string `json:"latest"`
	URL    string `json:"url,omitempty"`    // download location of the binary for this platform (optional)
	SHA256 string `json:"sha256,omitempty"` // hex digest of that binary; required for a self-update
}

// Checker remembers the newest version it has seen so the heartbeat can report it cheaply.
type Checker struct {
	Current string
	Fetch   func(ctx context.Context) (Info, error)

	mu     sync.Mutex
	latest Info
}

// Check asks the server and stores the answer. A failure leaves the previous answer in place.
func (c *Checker) Check(ctx context.Context) (Info, error) {
	info, err := c.Fetch(ctx)
	if err != nil {
		return Info{}, err
	}
	c.mu.Lock()
	c.latest = info
	c.mu.Unlock()

	return info, nil
}

// Available returns the advertised version when it is newer than the running one, else "".
func (c *Checker) Available() string {
	c.mu.Lock()
	defer c.mu.Unlock()
	if Newer(c.latest.Latest, c.Current) {
		return c.latest.Latest
	}

	return ""
}

func (c *Checker) Info() Info {
	c.mu.Lock()
	defer c.mu.Unlock()

	return c.latest
}

// FetchHTTP returns a Fetch function for the agent API.
func FetchHTTP(client *transport.Client) func(context.Context) (Info, error) {
	return func(ctx context.Context) (Info, error) {
		var info Info
		err := client.Do(ctx, "GET", "/agent/v1/version", nil, &info)

		return info, err
	}
}

// Newer reports whether version a is strictly newer than b. Versions are dotted numbers with an optional
// "v" prefix and "-suffix"; a pre-release (a "-dev" suffix) sorts before the same release. Anything
// unparsable is never "newer", so a malformed answer cannot trigger an update.
func Newer(a, b string) bool {
	pa, preA, okA := parse(a)
	pb, preB, okB := parse(b)
	if !okA || !okB {
		return false
	}
	for i := 0; i < max(len(pa), len(pb)); i++ {
		x, y := at(pa, i), at(pb, i)
		if x != y {
			return x > y
		}
	}

	return preB && !preA
}

func at(v []int, i int) int {
	if i < len(v) {
		return v[i]
	}

	return 0
}

func parse(v string) (nums []int, pre, ok bool) {
	v = strings.TrimPrefix(strings.TrimSpace(v), "v")
	core, suffix, hasSuffix := strings.Cut(v, "-")
	if core == "" {
		return nil, false, false
	}
	for _, part := range strings.Split(core, ".") {
		n, err := strconv.Atoi(part)
		if err != nil || n < 0 {
			return nil, false, false
		}
		nums = append(nums, n)
	}

	return nums, hasSuffix && suffix != "", true
}

// Apply downloads the advertised binary, verifies its SHA-256 and atomically replaces the executable at target.
// The caller then exits so its supervisor (Docker restart policy, systemd) starts the new version. Without a
// digest nothing is installed: an unverified binary must never replace a running agent.
func Apply(ctx context.Context, httpClient *http.Client, info Info, target string) error {
	if info.URL == "" || info.SHA256 == "" {
		return errors.New("the server did not provide a download URL and SHA-256, refusing to self-update")
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, info.URL, nil)
	if err != nil {
		return err
	}
	resp, err := httpClient.Do(req)
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("download returned %d", resp.StatusCode)
	}

	tmp, err := os.CreateTemp(filepath.Dir(target), ".jogger-agent-update-*")
	if err != nil {
		return fmt.Errorf("cannot stage the update next to %s: %w", target, err)
	}
	defer os.Remove(tmp.Name())

	sum := sha256.New()
	if _, err := io.Copy(io.MultiWriter(tmp, sum), io.LimitReader(resp.Body, 256<<20)); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	if got := hex.EncodeToString(sum.Sum(nil)); !strings.EqualFold(got, info.SHA256) {
		return fmt.Errorf("checksum mismatch: downloaded %s, expected %s", got, info.SHA256)
	}
	if err := os.Chmod(tmp.Name(), 0o755); err != nil {
		return err
	}

	return os.Rename(tmp.Name(), target)
}
