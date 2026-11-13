package docker

import (
	"context"
	"errors"
	"fmt"
	"net/url"
	"strings"
	"time"
)

// Replacement is a new agent container that has been created next to the running one but not started yet.
type Replacement struct {
	c       *Client
	oldID   string
	newID   string
	name    string
	oldName string
}

// PrepareReplacement pulls the image and creates a copy of the container `self` (its id or hostname) that runs the
// new image with the same configuration, mounts, networks and restart policy. The old container is renamed out of
// the way and the copy takes its name, so a plain `docker ps` still shows one "jogger-agent". Nothing is stopped:
// if anything fails here the running agent is untouched.
func (c *Client) PrepareReplacement(ctx context.Context, self, image string, auth *RegistryAuth) (*Replacement, error) {
	var inspected map[string]any
	if err := c.do(ctx, "GET", "/containers/"+url.PathEscape(self)+"/json", nil, nil, &inspected); err != nil {
		return nil, fmt.Errorf("inspecting the agent's own container: %w", err)
	}
	id, _ := inspected["Id"].(string)
	name := strings.TrimPrefix(asString(inspected["Name"]), "/")
	if id == "" || name == "" {
		return nil, errors.New("the agent's container has no id or name")
	}

	if err := c.Pull(ctx, image, auth); err != nil {
		return nil, err
	}

	body := replacementBody(inspected, image)
	tmp := name + "-new"
	_ = c.do(ctx, "DELETE", "/containers/"+url.PathEscape(tmp), queryOf("force", "1"), nil, nil)
	var created struct {
		ID string `json:"Id"`
	}
	if err := c.do(ctx, "POST", "/containers/create", queryOf("name", tmp), body, &created); err != nil {
		return nil, fmt.Errorf("creating the new agent container: %w", err)
	}

	return &Replacement{c: c, oldID: id, newID: created.ID, name: name, oldName: name + "-old"}, nil
}

// Finish swaps the containers: the old one is renamed, the new one takes its name and starts, then the old one is
// removed. Removing the container this process runs in ends the process, so call it last and after acking.
func (r *Replacement) Finish(ctx context.Context) error {
	id := url.PathEscape(r.oldID)
	_ = r.c.do(ctx, "DELETE", "/containers/"+url.PathEscape(r.oldName), queryOf("force", "1"), nil, nil)
	if err := r.c.do(ctx, "POST", "/containers/"+id+"/rename", queryOf("name", r.oldName), nil, nil); err != nil {
		return fmt.Errorf("renaming the old agent: %w", err)
	}
	if err := r.c.do(ctx, "POST", "/containers/"+url.PathEscape(r.newID)+"/rename", queryOf("name", r.name), nil, nil); err != nil {
		_ = r.c.do(ctx, "POST", "/containers/"+id+"/rename", queryOf("name", r.name), nil, nil)

		return fmt.Errorf("naming the new agent: %w", err)
	}
	if err := r.c.do(ctx, "POST", "/containers/"+url.PathEscape(r.newID)+"/start", nil, nil, nil); err != nil {
		// Put things back: the old agent keeps running under its own name.
		_ = r.c.do(ctx, "POST", "/containers/"+url.PathEscape(r.newID)+"/rename", queryOf("name", r.name+"-failed"), nil, nil)
		_ = r.c.do(ctx, "POST", "/containers/"+id+"/rename", queryOf("name", r.name), nil, nil)

		return fmt.Errorf("starting the new agent: %w", err)
	}
	time.Sleep(2 * time.Second)

	return r.c.do(ctx, "DELETE", "/containers/"+id, queryOf("force", "1"), nil, nil)
}

// replacementBody turns an inspected container into the create request of its replacement.
func replacementBody(inspected map[string]any, image string) map[string]any {
	body := map[string]any{}
	if cfg, ok := inspected["Config"].(map[string]any); ok {
		for k, v := range cfg {
			body[k] = v
		}
	}
	body["Image"] = image
	// The old hostname is the old container's id; let the engine pick the new one.
	delete(body, "Hostname")
	if hc, ok := inspected["HostConfig"].(map[string]any); ok {
		body["HostConfig"] = hc
	}

	endpoints := map[string]any{}
	if ns, ok := inspected["NetworkSettings"].(map[string]any); ok {
		if nets, ok := ns["Networks"].(map[string]any); ok {
			for name := range nets {
				endpoints[name] = map[string]any{}
			}
		}
	}
	if len(endpoints) > 0 {
		body["NetworkingConfig"] = map[string]any{"EndpointsConfig": endpoints}
	}

	return body
}

func asString(v any) string {
	s, _ := v.(string)

	return s
}
