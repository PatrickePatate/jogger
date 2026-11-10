package docker

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"strings"
)

// RegistryAuth is what a private registry needs to let an image be pulled. Never logged.
type RegistryAuth struct {
	Server   string `json:"server"`
	Username string `json:"username"`
	Password string `json:"password"`
}

// header is the X-Registry-Auth header the Docker Engine expects; nil without credentials.
func (a *RegistryAuth) header() map[string]string {
	if a == nil || a.Username == "" {
		return nil
	}
	raw, _ := json.Marshal(map[string]string{"username": a.Username, "password": a.Password, "serveraddress": a.Server})

	return map[string]string{"X-Registry-Auth": base64.URLEncoding.EncodeToString(raw)}
}

// HasImage reports whether the image is already present locally.
func (c *Client) HasImage(ctx context.Context, ref string) (bool, error) {
	err := c.do(ctx, "GET", "/images/"+url.PathEscape(ref)+"/json", nil, nil, nil)
	if IsNotFound(err) {
		return false, nil
	}

	return err == nil, err
}

// Pull downloads an image and waits for the stream to finish; the engine reports pull failures inside a 200 stream.
func (c *Client) Pull(ctx context.Context, ref string, auth *RegistryAuth) error {
	image, tag := splitRef(ref)
	resp, err := c.requestWith(ctx, "POST", "/images/create", url.Values{"fromImage": {image}, "tag": {tag}}, nil, auth.header())
	if err != nil {
		return fmt.Errorf("pulling %s: %w", ref, err)
	}
	defer resp.Body.Close()

	dec := json.NewDecoder(resp.Body)
	for {
		var msg struct {
			Error string `json:"error"`
		}
		if err := dec.Decode(&msg); errors.Is(err, io.EOF) {
			return nil
		} else if err != nil {
			return fmt.Errorf("pulling %s: %w", ref, err)
		}
		if msg.Error != "" {
			return fmt.Errorf("pulling %s: %s", ref, msg.Error)
		}
	}
}

// EnsureImage pulls only when the image is missing.
func (c *Client) EnsureImage(ctx context.Context, ref string, auth *RegistryAuth) error {
	if ok, err := c.HasImage(ctx, ref); err != nil || ok {
		return err
	}

	return c.Pull(ctx, ref, auth)
}

// splitRef separates "repo[:port]/name:tag" or "name@sha256:…" into the pieces the pull endpoint wants.
func splitRef(ref string) (image, tag string) {
	if i := strings.Index(ref, "@"); i >= 0 {
		return ref[:i], ref[i+1:]
	}
	if i := strings.LastIndex(ref, ":"); i > strings.LastIndex(ref, "/") {
		return ref[:i], ref[i+1:]
	}

	return ref, "latest"
}
