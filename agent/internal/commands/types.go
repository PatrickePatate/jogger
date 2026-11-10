// Package commands receives work from the control plane and runs it exactly once per command id.
package commands

import (
	"context"
	"errors"
)

// Command is one unit of work the server wants this host to perform.
type Command struct {
	ID      string         `json:"id"`
	Type    string         `json:"type"`
	Payload map[string]any `json:"payload"`
}

// Handler executes one command type. It must be safe to run again for the same command (delivery is
// at-least-once); the returned map is reported back as the command's result.
type Handler func(ctx context.Context, cmd Command) (map[string]any, error)

// API is the slice of the control plane the pipeline needs.
type API interface {
	Fetch(ctx context.Context) ([]Command, error)
	Ack(ctx context.Context, id string, ok bool, result map[string]any) error
}

// ErrUnsupported is reported for command types this agent version does not know.
var ErrUnsupported = errors.New("unsupported")
