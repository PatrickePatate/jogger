package telemetry

import (
	"os"
	"path/filepath"
	"testing"
)

func proc(t *testing.T, files map[string]string) string {
	dir := t.TempDir()
	for name, content := range files {
		path := filepath.Join(dir, "sys", name)
		if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}

	return dir
}

func TestDindSupported(t *testing.T) {
	cases := map[string]struct {
		files map[string]string
		want  bool
	}{
		"plain kernel":              {map[string]string{"user/max_user_namespaces": "63000\n"}, true},
		"namespaces disabled":       {map[string]string{"user/max_user_namespaces": "0\n"}, false},
		"no namespace support":      {map[string]string{}, false},
		"debian sysctl off":         {map[string]string{"user/max_user_namespaces": "100", "kernel/unprivileged_userns_clone": "0"}, false},
		"debian sysctl on":          {map[string]string{"user/max_user_namespaces": "100", "kernel/unprivileged_userns_clone": "1"}, true},
		"ubuntu apparmor restricts": {map[string]string{"user/max_user_namespaces": "100", "kernel/apparmor_restrict_unprivileged_userns": "1"}, false},
		"ubuntu apparmor relaxed":   {map[string]string{"user/max_user_namespaces": "100", "kernel/apparmor_restrict_unprivileged_userns": "0"}, true},
		"garbage":                   {map[string]string{"user/max_user_namespaces": "lots"}, false},
	}
	for name, c := range cases {
		if got := DindSupported(proc(t, c.files)); got != c.want {
			t.Errorf("%s: got %v, want %v", name, got, c.want)
		}
	}
}

func TestEnrollmentAndHeartbeatCarryDindFlag(t *testing.T) {
	yes := true
	f := Facts{Arch: "x64", OS: "linux", AgentVersion: "1", CPUTotal: 2, DindSupported: &yes}
	if f.Enrollment()["dind_supported"] != true {
		t.Fatal("enrollment lacks dind_supported")
	}
}
