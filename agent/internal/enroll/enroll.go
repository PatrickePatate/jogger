// Package enroll exchanges the one-time token for a long-lived credential and keeps it on disk.
package enroll

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"

	"github.com/PatrickePatate/jogger/agent/internal/telemetry"
	"github.com/PatrickePatate/jogger/agent/internal/transport"
)

const credentialFile = "credential.json"

// Credential is what enrollment hands back; it is stored with mode 0600.
type Credential struct {
	Server     string  `json:"server"`
	HostID     string  `json:"host_id"`
	Name       string  `json:"name"`
	Credential string  `json:"credential"`
	Reverb     *Reverb `json:"reverb,omitempty"`
}

// Reverb tells the agent where the realtime server is.
type Reverb struct {
	Host   string `json:"host"`
	Port   int    `json:"port"`
	Scheme string `json:"scheme"`
	Key    string `json:"key"`
}

// ErrNoToken means the agent has no credential yet and was not given an enrollment token.
var ErrNoToken = errors.New("this agent is not enrolled yet: provide a one-time enrollment token (--token or JOGGER_ENROLL_TOKEN)")

// Ensure returns the stored credential, or enrolls with the token and stores the result. A stored credential
// always wins, so a restarted container with a stale (already used) token keeps working.
func Ensure(ctx context.Context, server, token, hostname, dataDir string, facts telemetry.Facts, newClient func(credential string) *transport.Client) (Credential, bool, error) {
	if stored, err := Load(dataDir); err == nil {
		if stored.Server != server {
			return Credential{}, false, fmt.Errorf("the stored credential was issued by %s but this agent is configured for %s: remove %s to enroll again",
				stored.Server, server, filepath.Join(dataDir, credentialFile))
		}

		return stored, false, nil
	} else if !errors.Is(err, os.ErrNotExist) {
		return Credential{}, false, err
	}

	if token == "" {
		return Credential{}, false, ErrNoToken
	}

	var resp struct {
		HostID     string  `json:"host_id"`
		Name       string  `json:"name"`
		Credential string  `json:"credential"`
		Reverb     *Reverb `json:"reverb"`
	}
	body := map[string]any{"token": token, "hostname": hostname, "facts": facts.Enrollment()}
	if err := newClient("").Do(ctx, "POST", "/agent/v1/enroll", body, &resp); err != nil {
		return Credential{}, false, fmt.Errorf("enrolling: %w", err)
	}

	cred := Credential{Server: server, HostID: resp.HostID, Name: resp.Name, Credential: resp.Credential, Reverb: resp.Reverb}
	if err := Save(dataDir, cred); err != nil {
		return Credential{}, false, fmt.Errorf("the host was enrolled but its credential could not be saved (it will not be shown again; revoke the host and enroll again): %w", err)
	}

	return cred, true, nil
}

// Load reads the stored credential (os.ErrNotExist when there is none).
func Load(dataDir string) (Credential, error) {
	raw, err := os.ReadFile(filepath.Join(dataDir, credentialFile))
	if err != nil {
		return Credential{}, err
	}
	var cred Credential
	if err := json.Unmarshal(raw, &cred); err != nil || cred.Credential == "" {
		return Credential{}, fmt.Errorf("%s is corrupt: remove it and enroll again", filepath.Join(dataDir, credentialFile))
	}

	return cred, nil
}

// Save writes the credential atomically with mode 0600 inside a 0700 directory.
func Save(dataDir string, cred Credential) error {
	if err := os.MkdirAll(dataDir, 0o700); err != nil {
		return err
	}
	raw, err := json.MarshalIndent(cred, "", "  ")
	if err != nil {
		return err
	}

	tmp, err := os.CreateTemp(dataDir, ".credential-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())

	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if _, err := tmp.Write(raw); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}

	return os.Rename(tmp.Name(), filepath.Join(dataDir, credentialFile))
}
