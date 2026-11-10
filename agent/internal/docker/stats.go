package docker

import (
	"context"
	"encoding/json"
	"net/url"
)

// Container is the subset of the engine's container summary the agent uses.
type Container struct {
	ID      string            `json:"Id"`
	Names   []string          `json:"Names"`
	State   string            `json:"State"`
	Created int64             `json:"Created"`
	Labels  map[string]string `json:"Labels"`
}

// Containers lists containers matching the engine filters (all includes stopped ones).
func (c *Client) Containers(ctx context.Context, filters map[string][]string, all bool) ([]Container, error) {
	q := url.Values{}
	if all {
		q.Set("all", "true")
	}
	if len(filters) > 0 {
		raw, err := json.Marshal(filters)
		if err != nil {
			return nil, err
		}
		q.Set("filters", string(raw))
	}
	var out []Container
	err := c.do(ctx, "GET", "/containers/json", q, nil, &out)

	return out, err
}

// ManagedRunning counts this host's running runner containers.
func (c *Client) ManagedRunning(ctx context.Context, hostID string) (int, error) {
	list, err := c.Containers(ctx, map[string][]string{"label": {LabelHost + "=" + hostID}, "status": {"running"}}, false)

	return len(list), err
}
