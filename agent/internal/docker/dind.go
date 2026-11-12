package docker

import (
	"context"
	"errors"
	"fmt"
	"net/http"
	"net/url"
)

const (
	LabelSidecar = "jogger.sidecar"

	dindAlias = "docker"
	certsDir  = "/certs"
)

func sidecarName(id string) string { return "jogger-dind-" + id }
func certsVolume(id string) string { return "jogger-certs-" + id }

// sidecarBody builds the create request for a runner's private Docker daemon. The job talks to it over TLS on
// the runner's own network; the host's Docker socket is never mounted anywhere.
//
// Rootless dind still needs the container itself to be privileged (the daemon creates user namespaces and
// mounts inside it), so this is the one place the agent grants it — to a sidecar that holds no secrets, shares
// no network with other runners, and dies with the runner.
func sidecarBody(s Spec, image, net string, labels map[string]string) map[string]any {
	host := map[string]any{
		"NetworkMode": net,
		"AutoRemove":  true,
		"Privileged":  true,
		"PidsLimit":   pidsLimit,
		"Mounts":      []map[string]any{{"Type": "volume", "Source": certsVolume(s.RunnerID), "Target": certsDir}},
	}
	if s.CPU > 0 {
		host["NanoCpus"] = int64(s.CPU * 1e9)
	}
	if s.MemoryMB > 0 {
		host["Memory"] = s.MemoryMB << 20
		host["MemorySwap"] = s.MemoryMB << 20
	}

	body := map[string]any{
		"Image":      image,
		"Env":        []string{"DOCKER_TLS_CERTDIR=" + certsDir},
		"Labels":     labels,
		"HostConfig": host,
		"NetworkingConfig": map[string]any{"EndpointsConfig": map[string]any{
			net: map[string]any{"Aliases": []string{dindAlias}},
		}},
	}
	if s.Name != "" {
		body["Hostname"] = s.Name // the runner shares this namespace, so this is the name jobs see
	}

	return body
}

// dindImage is the sidecar image: the command's, else the host's (JOGGER_DIND_IMAGE), else the rootless default.
// Hosts where rootless cannot work (for example an unprivileged LXC container, which has no spare sub-uids)
// set the rootful "docker:dind".
func (r *Runner) dindImage(s Spec) string {
	for _, image := range []string{s.DindImage, r.DindImage} {
		if image != "" {
			return image
		}
	}

	return DefaultDindImage
}

func sidecarLabels(s Spec, hostID string) map[string]string {
	return map[string]string{LabelSidecar: s.RunnerID, LabelHost: hostID}
}

// startDind creates the certificate volume and starts the sidecar on the runner's network.
func (r *Runner) startDind(ctx context.Context, s Spec, net string) error {
	image := r.dindImage(s)
	if err := r.Docker.EnsureImage(ctx, image, nil); err != nil {
		return err
	}
	labels := sidecarLabels(s, r.HostID)
	if err := r.Docker.do(ctx, "POST", "/volumes/create", nil, map[string]any{"Name": certsVolume(s.RunnerID), "Labels": labels}, nil); err != nil {
		return fmt.Errorf("creating certificate volume: %w", err)
	}

	var created struct {
		ID string `json:"Id"`
	}
	if err := r.Docker.do(ctx, "POST", "/containers/create", url.Values{"name": {sidecarName(s.RunnerID)}}, sidecarBody(s, image, net, labels), &created); err != nil {
		return fmt.Errorf("creating dind sidecar: %w", err)
	}
	if err := r.Docker.do(ctx, "POST", "/containers/"+created.ID+"/start", nil, nil, nil); err != nil {
		return fmt.Errorf("starting dind sidecar: %w", err)
	}

	return nil
}

// teardown removes everything around a runner container: its sidecar, certificate volume and network.
// Missing pieces are fine, so it is safe to call repeatedly.
func (r *Runner) teardown(ctx context.Context, runnerID string) error {
	list, err := r.Docker.Containers(ctx, map[string][]string{"label": {LabelSidecar + "=" + runnerID}}, true)
	if err != nil {
		return err
	}
	for _, c := range list {
		q := url.Values{"force": {"true"}, "v": {"true"}}
		if err := r.Docker.do(ctx, "DELETE", "/containers/"+c.ID, q, nil, nil); err != nil && !IsNotFound(err) {
			var de *Error
			if !errors.As(err, &de) || de.Status != http.StatusConflict {
				return fmt.Errorf("removing dind sidecar: %w", err)
			}
		}
	}
	if err := r.Docker.RemoveNetwork(ctx, networkName(runnerID)); err != nil {
		return err
	}
	// The volume can only go once its containers are gone.
	if err := r.Docker.do(ctx, "DELETE", "/volumes/"+certsVolume(runnerID), nil, nil, nil); err != nil && !IsNotFound(err) {
		return fmt.Errorf("removing certificate volume: %w", err)
	}

	return nil
}
