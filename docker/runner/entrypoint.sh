#!/usr/bin/env bash
# Starts the GitHub Actions runner for exactly one job, using the single-use JIT config Jogger passes in.
set -euo pipefail

if [ -z "${JIT_CONFIG:-}" ]; then
  echo "jogger-runner: JIT_CONFIG is not set. This image is started by the Jogger agent with a single-use" >&2
  echo "runner credential; it cannot be run on its own." >&2
  exit 64
fi

# Keep the credential out of the environment that job steps inherit.
jit="$JIT_CONFIG"
unset JIT_CONFIG

cd "${RUNNER_HOME:-/home/runner}"

# The runner sends step output to GitHub only, but also writes it to _diag/pages while a step runs (already with
# secrets masked). Echo those files to stdout, which is what the Jogger agent ships, so the job's log is live in
# Jogger too. Without this, Jogger would only see the listener's few lines.
follow_step_logs() {
  local dir="$1" f
  declare -A seen=()
  while :; do
    for f in "$dir"/*.log; do
      [ -e "$f" ] || continue
      [ -n "${seen[$f]:-}" ] && continue
      seen[$f]=1
      tail -n +1 -f "$f" 2>/dev/null &
    done
    sleep 0.2
  done
}
if [ "${JOGGER_STEP_LOGS:-1}" != "0" ]; then
  follow_step_logs "$PWD/_diag/pages" &
fi

exec ./run.sh --jitconfig "$jit"
