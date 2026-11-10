package enroll

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/PatrickePatate/jogger/agent/internal/telemetry"
	"github.com/PatrickePatate/jogger/agent/internal/transport"
)

func fakeServer(t *testing.T, calls *int) *httptest.Server {
	t.Helper()

	return httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		*calls++
		var body struct {
			Token    string         `json:"token"`
			Hostname string         `json:"hostname"`
			Facts    map[string]any `json:"facts"`
		}
		json.NewDecoder(r.Body).Decode(&body)
		if body.Token != "jhe_good" {
			w.WriteHeader(401)
			w.Write([]byte(`{"error":{"code":"INVALID_ENROLLMENT_TOKEN","message":"nope"}}`))
			return
		}
		if body.Hostname != "box-1" || body.Facts["arch"] == nil {
			t.Errorf("unexpected enrollment body: %+v", body)
		}
		w.WriteHeader(201)
		w.Write([]byte(`{"host_id":"h-1","name":"box-1","credential":"jha_secret","reverb":{"host":"localhost","port":8080,"scheme":"http","key":"k"}}`))
	}))
}

func ensure(t *testing.T, srv *httptest.Server, token, dir string) (Credential, bool, error) {
	t.Helper()
	newClient := func(c string) *transport.Client { return transport.New(srv.URL, c, "t") }

	return Ensure(context.Background(), srv.URL, token, "box-1", dir, telemetry.Facts{Arch: "x64", OS: "linux", AgentVersion: "t", CPUTotal: 4}, newClient)
}

func TestEnrollsOnceStoresCredentialAndRestartsWithoutToken(t *testing.T) {
	calls := 0
	srv := fakeServer(t, &calls)
	defer srv.Close()
	dir := filepath.Join(t.TempDir(), "data")

	cred, enrolled, err := ensure(t, srv, "jhe_good", dir)
	if err != nil || !enrolled || cred.Credential != "jha_secret" || cred.HostID != "h-1" || cred.Reverb.Port != 8080 {
		t.Fatalf("first start: %+v enrolled=%v err=%v", cred, enrolled, err)
	}

	info, err := os.Stat(filepath.Join(dir, credentialFile))
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o600 {
		t.Errorf("credential file mode = %v, want 0600", info.Mode().Perm())
	}
	if dirInfo, _ := os.Stat(dir); dirInfo.Mode().Perm() != 0o700 {
		t.Errorf("data dir mode = %v, want 0700", dirInfo.Mode().Perm())
	}

	// Restart: no token at all.
	again, enrolled, err := ensure(t, srv, "", dir)
	if err != nil || enrolled || again.Credential != "jha_secret" {
		t.Fatalf("restart: %+v enrolled=%v err=%v", again, enrolled, err)
	}
	// Restart with the (now used) token still configured, as a container with a fixed env would have.
	if _, enrolled, err = ensure(t, srv, "jhe_good", dir); err != nil || enrolled {
		t.Fatalf("restart with stale token: enrolled=%v err=%v", enrolled, err)
	}
	if calls != 1 {
		t.Errorf("the server was contacted %d times, want 1", calls)
	}
}

func TestWithoutCredentialOrTokenItExplains(t *testing.T) {
	calls := 0
	srv := fakeServer(t, &calls)
	defer srv.Close()

	_, _, err := ensure(t, srv, "", t.TempDir())
	if err != ErrNoToken || calls != 0 {
		t.Errorf("err=%v calls=%d", err, calls)
	}
}

func TestBadTokenIsAPermanentError(t *testing.T) {
	calls := 0
	srv := fakeServer(t, &calls)
	defer srv.Close()
	dir := t.TempDir()

	_, _, err := ensure(t, srv, "jhe_bad", dir)
	if err == nil || transport.Retryable(err) {
		t.Fatalf("err = %v, want a permanent error", err)
	}
	if _, statErr := os.Stat(filepath.Join(dir, credentialFile)); statErr == nil {
		t.Error("nothing may be stored after a failed enrollment")
	}
}

func TestCredentialFromAnotherServerIsRefused(t *testing.T) {
	calls := 0
	srv := fakeServer(t, &calls)
	defer srv.Close()
	dir := t.TempDir()
	Save(dir, Credential{Server: "https://other.example", HostID: "h", Credential: "jha_x"})

	_, _, err := ensure(t, srv, "jhe_good", dir)
	if err == nil || !strings.Contains(err.Error(), "other.example") {
		t.Errorf("err = %v", err)
	}
}

func TestCorruptCredentialFileIsReported(t *testing.T) {
	dir := t.TempDir()
	os.WriteFile(filepath.Join(dir, credentialFile), []byte("{nope"), 0o600)

	if _, err := Load(dir); err == nil || !strings.Contains(err.Error(), "corrupt") {
		t.Errorf("err = %v", err)
	}
}
