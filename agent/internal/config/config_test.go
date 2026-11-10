package config

import (
	"os"
	"strings"
	"testing"
	"time"
)

func env(m map[string]string) Env { return func(k string) string { return m[k] } }

func files(m map[string]string) func(string) ([]byte, error) {
	return func(p string) ([]byte, error) {
		if v, ok := m[p]; ok {
			return []byte(v), nil
		}

		return nil, os.ErrNotExist
	}
}

func host() (string, error) { return "box-1", nil }

func TestFlagsBeatEnvBeatFile(t *testing.T) {
	file := files(map[string]string{DefaultConfigFile: "server = \"https://file.example\"\ntoken = \"from-file\"\nname = \"file-name\"\n"})
	e := env(map[string]string{"JOGGER_SERVER": "https://env.example", "JOGGER_ENROLL_TOKEN": "from-env"})

	cfg, err := Load([]string{"--server", "https://flag.example/"}, e, file, host)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Server != "https://flag.example" {
		t.Errorf("server = %q, want the flag (trailing slash trimmed)", cfg.Server)
	}
	if cfg.Token != "from-env" {
		t.Errorf("token = %q, want env over file", cfg.Token)
	}
	if cfg.Hostname != "file-name" {
		t.Errorf("hostname = %q, want the file value", cfg.Hostname)
	}
}

func TestDefaults(t *testing.T) {
	cfg, err := Load([]string{"--server", "http://localhost:8000"}, env(nil), files(nil), host)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.DataDir != DefaultDataDir || cfg.HeartbeatInterval != 10*time.Second || cfg.Hostname != "box-1" || cfg.DockerSocket != "/var/run/docker.sock" {
		t.Errorf("unexpected defaults: %+v", cfg)
	}
}

func TestServerIsRequiredAndMustBeAURL(t *testing.T) {
	if _, err := Load(nil, env(nil), files(nil), host); err == nil || !strings.Contains(err.Error(), "no server URL") {
		t.Errorf("want a missing-server error, got %v", err)
	}
	if _, err := Load([]string{"--server", "jogger.example"}, env(nil), files(nil), host); err == nil {
		t.Error("a server without a scheme must be rejected")
	}
}

func TestIntervalForms(t *testing.T) {
	for raw, want := range map[string]time.Duration{"5": 5 * time.Second, "15s": 15 * time.Second, "1m": time.Minute} {
		cfg, err := Load([]string{"--server", "http://x"}, env(map[string]string{"JOGGER_HEARTBEAT_INTERVAL": raw}), files(nil), host)
		if err != nil || cfg.HeartbeatInterval != want {
			t.Errorf("%q: got %v, %v; want %v", raw, cfg.HeartbeatInterval, err, want)
		}
	}
	if _, err := Load([]string{"--server", "http://x", "--heartbeat-interval", "100ms"}, env(nil), files(nil), host); err == nil {
		t.Error("an interval under 1s must be rejected")
	}
	if _, err := Load([]string{"--server", "http://x"}, env(map[string]string{"JOGGER_HEARTBEAT_INTERVAL": "soon"}), files(nil), host); err == nil {
		t.Error("garbage must be rejected")
	}
}

func TestConfigFileParsing(t *testing.T) {
	body := "# comment\n\nserver = 'https://a.example'  \ndata_dir = \"/data\" \nname = plain # trailing\n"
	cfg, err := Load([]string{"--config", "/custom.toml"}, env(nil), files(map[string]string{"/custom.toml": body}), host)
	if err != nil {
		t.Fatal(err)
	}
	if cfg.Server != "https://a.example" || cfg.DataDir != "/data" || cfg.Hostname != "plain" {
		t.Errorf("unexpected parse: %+v", cfg)
	}

	if _, err := Load([]string{"--config", "/bad.toml"}, env(nil), files(map[string]string{"/bad.toml": "[section]\n"}), host); err == nil {
		t.Error("tables are unsupported and must be reported, not ignored")
	}
}

func TestMissingDefaultFileIsFineButMissingExplicitFileIsNot(t *testing.T) {
	if _, err := Load([]string{"--server", "http://x"}, env(nil), files(nil), host); err != nil {
		t.Errorf("no default config file is normal: %v", err)
	}
	if _, err := Load([]string{"--server", "http://x", "--config", "/nope.toml"}, env(nil), files(nil), host); err == nil {
		t.Error("an explicitly requested file that is missing must fail")
	}
}
