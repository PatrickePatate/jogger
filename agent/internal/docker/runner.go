package docker

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"sort"
	"strings"
	"time"
)

const (
	LabelRunner = "jogger.runner"
	LabelHost   = "jogger.host"

	pidsLimit = 2048
)

// Spec is the payload of a runner.start command.
type Spec struct {
	RunnerID   string            `json:"runner_id"`
	Name       string            `json:"name"`
	Image      string            `json:"image"`
	JITConfig  string            `json:"jit_config"`
	CPU        float64           `json:"cpu"`
	MemoryMB   int64             `json:"memory_mb"`
	Env        map[string]string `json:"env"`
	DockerMode string            `json:"docker_mode"`
	Command    []string          `json:"command,omitempty"` // overrides the image's entrypoint arguments
	DindImage  string            `json:"dind_image,omitempty"`
	Registry   *RegistryAuth     `json:"registry_auth,omitempty"`
	// ToolCacheVolume, when set, is a named volume shared by the pool's runners on this host.
	ToolCacheVolume string `json:"tool_cache_volume,omitempty"`
}

// toolCachePrefix is the only shape of volume name the server may ask for: never a host path, never a foreign volume.
const toolCachePrefix = "jogger-toolcache-"

// DefaultDindImage is the rootless Docker-in-Docker sidecar used when docker_mode is "dind".
const DefaultDindImage = "docker:dind-rootless"

// Validate rejects specs the agent cannot run safely.
func (s Spec) Validate() error {
	switch {
	case s.RunnerID == "" || !safeID(s.RunnerID):
		return errors.New("runner_id is missing or malformed")
	case s.Image == "":
		return errors.New("image is required")
	case s.DockerMode != "" && s.DockerMode != "none" && s.DockerMode != "dind":
		return fmt.Errorf("docker_mode %q is not supported (use \"none\" or \"dind\")", s.DockerMode)
	case s.ToolCacheVolume != "" && (!strings.HasPrefix(s.ToolCacheVolume, toolCachePrefix) || len(s.ToolCacheVolume) == len(toolCachePrefix) || !safeID(s.ToolCacheVolume)):
		return errors.New("tool_cache_volume is malformed")
	case s.CPU < 0 || s.MemoryMB < 0:
		return errors.New("cpu and memory_mb cannot be negative")
	}

	return nil
}

func safeID(id string) bool {
	for _, r := range id {
		if !(r >= 'a' && r <= 'z' || r >= 'A' && r <= 'Z' || r >= '0' && r <= '9' || r == '-' || r == '_') {
			return false
		}
	}

	return len(id) <= 64
}

// Runner starts and stops runner containers for one host.
type Runner struct {
	Docker *Client
	HostID string
	// DindImage overrides the default sidecar image on this host; empty keeps DefaultDindImage.
	DindImage string
}

func containerName(id string) string { return "jogger-runner-" + id }
func networkName(id string) string   { return "jogger-net-" + id }

// Started describes a runner container.
type Started struct {
	ContainerID string
	Existing    bool // true when the runner was already running and nothing was created
}

// Start creates the runner's private network and container, then starts it. It is idempotent per runner id:
// repeating it (an at-least-once redelivery, an agent restart) finds the container and creates nothing.
func (r *Runner) Start(ctx context.Context, s Spec) (Started, error) {
	if err := s.Validate(); err != nil {
		return Started{}, err
	}
	if id, err := r.find(ctx, s.RunnerID); err != nil {
		return Started{}, err
	} else if id != "" {
		return Started{ContainerID: id, Existing: true}, nil
	}

	if err := r.Docker.EnsureImage(ctx, s.Image, s.Registry); err != nil {
		return Started{}, err
	}

	labels := map[string]string{LabelRunner: s.RunnerID, LabelHost: r.HostID}
	net := networkName(s.RunnerID)
	if err := r.Docker.CreateNetwork(ctx, net, labels); err != nil {
		return Started{}, fmt.Errorf("creating network: %w", err)
	}

	var id string
	var err error
	if s.DockerMode == "dind" {
		err = r.startDind(ctx, s, net)
	}
	if err == nil {
		err = r.ensureToolCache(ctx, s)
	}
	if err == nil {
		id, err = r.create(ctx, s, net, labels)
	}
	if err == nil {
		err = r.Docker.do(ctx, "POST", "/containers/"+id+"/start", nil, nil, nil)
	}
	if err != nil {
		// Do not leave half a runner behind: the retry would otherwise find a dead container and call it started.
		cleanup, cancel := context.WithTimeout(context.WithoutCancel(ctx), 30*time.Second)
		defer cancel()
		_ = r.remove(cleanup, s.RunnerID)

		return Started{}, fmt.Errorf("starting runner: %w", err)
	}

	return Started{ContainerID: id}, nil
}

func (r *Runner) create(ctx context.Context, s Spec, net string, labels map[string]string) (string, error) {
	env := []string{}
	keys := make([]string, 0, len(s.Env))
	for k := range s.Env {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	for _, k := range keys {
		env = append(env, k+"="+s.Env[k])
	}
	if s.JITConfig != "" {
		env = append(env, "JIT_CONFIG="+s.JITConfig)
	}
	if s.DockerMode == "dind" {
		env = append(env, "DOCKER_HOST=tcp://localhost:2376", "DOCKER_TLS_VERIFY=1", "DOCKER_CERT_PATH="+certsDir+"/client")
		// Share the sidecar's network namespace: ports published by service containers (`services:` with
		// `ports:`) are then on the job's own localhost, as on GitHub-hosted runners.
		net = "container:" + sidecarName(s.RunnerID)
	}

	host := map[string]any{
		"NetworkMode": net,
		"AutoRemove":  true,
		"SecurityOpt": []string{"no-new-privileges"},
		"CapDrop":     []string{"ALL"},
		"PidsLimit":   pidsLimit,
	}
	if s.CPU > 0 {
		host["NanoCpus"] = int64(s.CPU * 1e9)
	}
	if s.MemoryMB > 0 {
		host["Memory"] = s.MemoryMB << 20
		host["MemorySwap"] = s.MemoryMB << 20 // no swap: hitting the limit must OOM-kill, not thrash the host
	}

	mounts := []map[string]any{}
	if s.DockerMode == "dind" {
		mounts = append(mounts, map[string]any{"Type": "volume", "Source": certsVolume(s.RunnerID), "Target": certsDir, "ReadOnly": true})
	}
	if m := toolCacheMount(s); m != nil {
		mounts = append(mounts, m)
	}
	if len(mounts) > 0 {
		host["Mounts"] = mounts
	}

	body := map[string]any{"Image": s.Image, "Env": env, "Labels": labels, "HostConfig": host}
	if s.Name != "" && s.DockerMode != "dind" { // in dind mode the hostname belongs to the shared namespace's owner
		body["Hostname"] = s.Name
	}
	if len(s.Command) > 0 {
		body["Cmd"] = s.Command
	}

	var created struct {
		ID string `json:"Id"`
	}
	err := r.Docker.do(ctx, "POST", "/containers/create", url.Values{"name": {containerName(s.RunnerID)}}, body, &created)
	if err != nil {
		var de *Error
		if errors.As(err, &de) && de.Status == http.StatusConflict {
			if existing, ferr := r.find(ctx, s.RunnerID); ferr == nil && existing != "" {
				return existing, nil // lost a race with a concurrent identical start
			}
		}
		return "", err
	}

	return created.ID, nil
}

// Stop asks the runner to exit (SIGTERM, then SIGKILL after grace) and removes its container, anonymous
// volumes and network. A runner that is already gone is a success.
func (r *Runner) Stop(ctx context.Context, runnerID string, grace time.Duration) (stopped bool, err error) {
	if !safeID(runnerID) || runnerID == "" {
		return false, errors.New("runner_id is missing or malformed")
	}
	id, err := r.find(ctx, runnerID)
	if err != nil {
		return false, err
	}
	if id != "" {
		q := url.Values{"t": {fmt.Sprint(int(grace.Seconds()))}}
		if err := r.Docker.do(ctx, "POST", "/containers/"+id+"/stop", q, nil, nil); err != nil && !IsNotFound(err) {
			var de *Error
			// 304 means it was already stopped; AutoRemove may also have removed it under us.
			if !errors.As(err, &de) || de.Status != http.StatusNotModified {
				return false, fmt.Errorf("stopping runner: %w", err)
			}
		}
	}

	return id != "", r.remove(ctx, runnerID)
}

// remove force-deletes the container (with its anonymous volumes) and the network. Missing pieces are fine.
func (r *Runner) remove(ctx context.Context, runnerID string) error {
	if id, err := r.find(ctx, runnerID); err == nil && id != "" {
		q := url.Values{"force": {"true"}, "v": {"true"}}
		if err := r.Docker.do(ctx, "DELETE", "/containers/"+id, q, nil, nil); err != nil && !IsNotFound(err) {
			var de *Error
			// 409 = removal already in progress (AutoRemove racing us).
			if !errors.As(err, &de) || de.Status != http.StatusConflict {
				return fmt.Errorf("removing container: %w", err)
			}
		}
	}

	return r.teardown(ctx, runnerID)
}

// find returns the id of the runner's container, or "" when there is none.
func (r *Runner) find(ctx context.Context, runnerID string) (string, error) {
	list, err := r.Docker.Containers(ctx, map[string][]string{"label": {LabelRunner + "=" + runnerID}}, true)
	if err != nil || len(list) == 0 {
		return "", err
	}

	return list[0].ID, nil
}
