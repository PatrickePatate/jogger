// Package config resolves the agent's settings. Precedence, highest first: command-line flags, environment
// variables, the config file.
package config

import (
	"bufio"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"strconv"
	"strings"
	"time"
)

const (
	DefaultConfigFile = "/etc/jogger-agent/config.toml"
	DefaultDataDir    = "/var/lib/jogger-agent"
	DefaultInterval   = 10 * time.Second
)

// Config is everything the agent needs to start.
type Config struct {
	Server            string        // base URL of the Jogger control plane
	Token             string        // one-time enrollment token; only needed on first start
	DataDir           string        // where the credential is stored
	Hostname          string        // name offered at enrollment (the token may preset one)
	HeartbeatInterval time.Duration // how often capacity is reported
	DockerSocket      string        // path of the Docker engine socket
	ConfigFile        string
}

// Env looks up an environment variable (os.Getenv in production).
type Env func(key string) string

// Load reads flags (args excludes the program name), the environment and the config file.
func Load(args []string, env Env, readFile func(string) ([]byte, error), hostname func() (string, error)) (Config, error) {
	fs := flag.NewFlagSet("jogger-agent", flag.ContinueOnError)
	fs.SetOutput(io.Discard)
	var (
		server   = fs.String("server", "", "Jogger server URL")
		token    = fs.String("token", "", "one-time enrollment token")
		dataDir  = fs.String("data-dir", "", "directory for the stored credential")
		name     = fs.String("name", "", "host name to register")
		interval = fs.Duration("heartbeat-interval", 0, "heartbeat interval")
		socket   = fs.String("docker-socket", "", "Docker socket path")
		cfgFile  = fs.String("config", "", "config file path")
	)
	if err := fs.Parse(args); err != nil {
		return Config{}, fmt.Errorf("invalid arguments: %w", err)
	}

	file := firstNonEmpty(*cfgFile, env("JOGGER_CONFIG"))
	explicitFile := file != ""
	if file == "" {
		file = DefaultConfigFile
	}
	values, err := readConfigFile(file, readFile)
	if err != nil && (explicitFile || !errors.Is(err, os.ErrNotExist)) {
		return Config{}, err
	}

	pick := func(flagVal, envKey, fileKey, def string) string {
		return firstNonEmpty(flagVal, env(envKey), values[fileKey], def)
	}

	cfg := Config{
		Server:       strings.TrimRight(pick(*server, "JOGGER_SERVER", "server", ""), "/"),
		Token:        pick(*token, "JOGGER_ENROLL_TOKEN", "token", ""),
		DataDir:      pick(*dataDir, "JOGGER_DATA_DIR", "data_dir", DefaultDataDir),
		Hostname:     pick(*name, "JOGGER_HOSTNAME", "name", ""),
		DockerSocket: pick(*socket, "JOGGER_DOCKER_SOCKET", "docker_socket", "/var/run/docker.sock"),
		ConfigFile:   file,
	}

	cfg.HeartbeatInterval = DefaultInterval
	if raw := firstNonEmpty(intervalString(*interval), env("JOGGER_HEARTBEAT_INTERVAL"), values["heartbeat_interval"]); raw != "" {
		d, err := parseInterval(raw)
		if err != nil {
			return Config{}, fmt.Errorf("heartbeat interval %q: %w", raw, err)
		}
		cfg.HeartbeatInterval = d
	}

	if cfg.Hostname == "" {
		if h, err := hostname(); err == nil {
			cfg.Hostname = h
		}
	}

	return cfg, cfg.validate()
}

func (c Config) validate() error {
	if c.Server == "" {
		return errors.New("no server URL: pass --server, set JOGGER_SERVER or add `server = \"...\"` to the config file")
	}
	if !strings.HasPrefix(c.Server, "http://") && !strings.HasPrefix(c.Server, "https://") {
		return fmt.Errorf("server URL %q must start with http:// or https://", c.Server)
	}
	if c.Hostname == "" {
		return errors.New("could not determine a host name: pass --name")
	}
	if c.HeartbeatInterval < time.Second {
		return errors.New("heartbeat interval must be at least 1s")
	}

	return nil
}

func intervalString(d time.Duration) string {
	if d == 0 {
		return ""
	}

	return d.String()
}

// parseInterval accepts "10s"-style durations or a bare number of seconds.
func parseInterval(raw string) (time.Duration, error) {
	if n, err := strconv.Atoi(raw); err == nil {
		return time.Duration(n) * time.Second, nil
	}

	return time.ParseDuration(raw)
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}

	return ""
}

// readConfigFile parses the small flat subset of TOML the agent needs: `key = "value"` lines, `#` comments.
// Tables and arrays are not supported (and not needed), which keeps the agent free of dependencies.
func readConfigFile(path string, readFile func(string) ([]byte, error)) (map[string]string, error) {
	raw, err := readFile(path)
	if err != nil {
		return nil, fmt.Errorf("reading %s: %w", path, err)
	}

	values := map[string]string{}
	scanner := bufio.NewScanner(strings.NewReader(string(raw)))
	for n := 1; scanner.Scan(); n++ {
		line := strings.TrimSpace(scanner.Text())
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		key, val, ok := strings.Cut(line, "=")
		if !ok || strings.HasPrefix(line, "[") {
			return nil, fmt.Errorf("%s:%d: expected `key = \"value\"`", path, n)
		}
		val = strings.TrimSpace(val)
		if len(val) >= 2 && (val[0] == '"' || val[0] == '\'') && val[len(val)-1] == val[0] {
			val = val[1 : len(val)-1]
		} else if i := strings.Index(val, " #"); i >= 0 {
			val = strings.TrimSpace(val[:i])
		}
		values[strings.TrimSpace(key)] = val
	}

	return values, nil
}
