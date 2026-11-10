package telemetry

import (
	"os"
	"strconv"
	"strings"
)

// DindSupported reports whether rootless Docker-in-Docker can run here. It needs unprivileged user namespaces,
// which can be disabled outright (max_user_namespaces=0), by a Debian-style sysctl, or — on Ubuntu 24.04 and
// later — by an AppArmor restriction. A missing knob means that mechanism does not exist on this kernel.
func DindSupported(procDir string) bool {
	read := func(name string) (int, bool) {
		raw, err := os.ReadFile(procDir + "/sys/" + name)
		if err != nil {
			return 0, false
		}
		n, err := strconv.Atoi(strings.TrimSpace(string(raw)))

		return n, err == nil
	}

	if n, ok := read("user/max_user_namespaces"); !ok || n <= 0 {
		return false // no user namespace support at all (also the answer on non-Linux fixtures)
	}
	if n, ok := read("kernel/unprivileged_userns_clone"); ok && n == 0 {
		return false
	}
	if n, ok := read("kernel/apparmor_restrict_unprivileged_userns"); ok && n == 1 {
		return false
	}

	return true
}
