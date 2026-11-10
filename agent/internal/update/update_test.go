package update

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

func TestNewer(t *testing.T) {
	cases := []struct {
		a, b string
		want bool
	}{
		{"1.2.0", "1.1.9", true},
		{"v1.10.0", "1.9.0", true},
		{"1.0.0", "1.0.0", false},
		{"1.0.0", "1.0.0-dev", true}, // the release beats its own pre-release
		{"1.0.0-dev", "1.0.0", false},
		{"0.1.0", "0.1.0-dev", true},
		{"1.0", "1.0.0", false},
		{"2", "1.9.9", true},
		{"garbage", "1.0.0", false},
		{"1.0.0", "", false},
		{"", "1.0.0", false},
	}
	for _, c := range cases {
		if got := Newer(c.a, c.b); got != c.want {
			t.Errorf("Newer(%q, %q) = %v, want %v", c.a, c.b, got, c.want)
		}
	}
}

func TestCheckerReportsOnlyNewerVersions(t *testing.T) {
	latest := "0.2.0"
	c := &Checker{Current: "0.1.0-dev", Fetch: func(context.Context) (Info, error) { return Info{Latest: latest}, nil }}
	if c.Available() != "" {
		t.Fatal("nothing checked yet")
	}
	if _, err := c.Check(context.Background()); err != nil || c.Available() != "0.2.0" {
		t.Fatalf("available = %q (%v)", c.Available(), err)
	}

	// A failing check keeps the previous answer; being up to date clears it.
	c.Fetch = func(context.Context) (Info, error) { return Info{}, errors.New("down") }
	if _, err := c.Check(context.Background()); err == nil || c.Available() != "0.2.0" {
		t.Fatal("a failed check must not forget what it knew")
	}
	c.Current = "0.2.0"
	if c.Available() != "" {
		t.Fatal("up to date should report nothing")
	}
}

func serve(body string) *httptest.Server {
	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) { _, _ = w.Write([]byte(body)) }))
}

func TestApplyReplacesBinaryWhenChecksumMatches(t *testing.T) {
	srv := serve("new-binary")
	defer srv.Close()
	dir := t.TempDir()
	target := filepath.Join(dir, "jogger-agent")
	_ = os.WriteFile(target, []byte("old"), 0o755)

	sum := sha256.Sum256([]byte("new-binary"))
	if err := Apply(context.Background(), srv.Client(), Info{URL: srv.URL, SHA256: hex.EncodeToString(sum[:])}, target); err != nil {
		t.Fatal(err)
	}
	if got, _ := os.ReadFile(target); string(got) != "new-binary" {
		t.Fatalf("binary = %q", got)
	}
	if st, _ := os.Stat(target); st.Mode().Perm()&0o100 == 0 {
		t.Fatal("not executable")
	}
	if entries, _ := os.ReadDir(dir); len(entries) != 1 {
		t.Fatalf("staging file left behind: %v", entries)
	}
}

func TestApplyRefusesBadChecksumAndMissingDigest(t *testing.T) {
	srv := serve("tampered")
	defer srv.Close()
	target := filepath.Join(t.TempDir(), "jogger-agent")
	_ = os.WriteFile(target, []byte("old"), 0o755)

	if err := Apply(context.Background(), srv.Client(), Info{URL: srv.URL, SHA256: "00"}, target); err == nil {
		t.Fatal("a checksum mismatch must fail")
	}
	if err := Apply(context.Background(), srv.Client(), Info{URL: srv.URL}, target); err == nil {
		t.Fatal("no digest must refuse")
	}
	if got, _ := os.ReadFile(target); string(got) != "old" {
		t.Fatalf("binary was touched: %q", got)
	}
	if entries, _ := os.ReadDir(filepath.Dir(target)); len(entries) != 1 {
		t.Fatalf("staging file left behind: %v", entries)
	}
}
