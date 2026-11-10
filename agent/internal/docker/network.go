package docker

import (
	"context"
	"net/url"
)

// CreateNetwork makes an isolated bridge network for one runner, so runners cannot reach each other.
func (c *Client) CreateNetwork(ctx context.Context, name string, labels map[string]string) error {
	return c.do(ctx, "POST", "/networks/create", nil, map[string]any{
		"Name": name, "Driver": "bridge", "Labels": labels, "CheckDuplicate": true,
	}, nil)
}

// RemoveNetwork deletes a network; a network that is already gone is not an error.
func (c *Client) RemoveNetwork(ctx context.Context, name string) error {
	err := c.do(ctx, "DELETE", "/networks/"+url.PathEscape(name), nil, nil, nil)
	if IsNotFound(err) {
		return nil
	}

	return err
}
