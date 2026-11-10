package commands

import (
	"context"
	"net/url"

	"github.com/PatrickePatate/jogger/agent/internal/transport"
)

// HTTPAPI implements API over the agent HTTP endpoints.
type HTTPAPI struct{ Client *transport.Client }

func (a HTTPAPI) Fetch(ctx context.Context) ([]Command, error) {
	var resp struct {
		Commands []Command `json:"commands"`
	}
	if err := a.Client.Do(ctx, "GET", "/agent/v1/commands", nil, &resp); err != nil {
		return nil, err
	}

	return resp.Commands, nil
}

func (a HTTPAPI) Ack(ctx context.Context, id string, ok bool, result map[string]any) error {
	body := map[string]any{"ok": ok}
	if result != nil {
		body["result"] = result
	}

	return a.Client.Do(ctx, "POST", "/agent/v1/commands/"+url.PathEscape(id)+"/ack", body, nil)
}
