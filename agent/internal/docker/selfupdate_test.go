package docker

import "testing"

func TestReplacementKeepsConfigButChangesImageAndHostname(t *testing.T) {
	inspected := map[string]any{
		"Config": map[string]any{
			"Image": "ghcr.io/x/jogger-agent:1.0.0", "Hostname": "abc123", "Env": []any{"JOGGER_URL=https://j"},
			"Entrypoint": []any{"/usr/local/bin/jogger-agent"}, "User": "root",
		},
		"HostConfig":      map[string]any{"Binds": []any{"/var/run/docker.sock:/var/run/docker.sock"}, "RestartPolicy": map[string]any{"Name": "unless-stopped"}},
		"NetworkSettings": map[string]any{"Networks": map[string]any{"bridge": map[string]any{"IPAddress": "172.17.0.2"}}},
	}

	body := replacementBody(inspected, "ghcr.io/x/jogger-agent:1.1.0")

	if body["Image"] != "ghcr.io/x/jogger-agent:1.1.0" {
		t.Errorf("image not replaced: %v", body["Image"])
	}
	if _, has := body["Hostname"]; has {
		t.Error("the old hostname must not be carried over")
	}
	if body["User"] != "root" || body["Env"] == nil || body["Entrypoint"] == nil {
		t.Errorf("config lost: %v", body)
	}
	hc := body["HostConfig"].(map[string]any)
	if hc["Binds"] == nil || hc["RestartPolicy"] == nil {
		t.Errorf("host config lost: %v", hc)
	}
	eps := body["NetworkingConfig"].(map[string]any)["EndpointsConfig"].(map[string]any)
	if _, ok := eps["bridge"]; !ok || len(eps) != 1 {
		t.Errorf("networks lost: %v", eps)
	}
}
