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
exec ./run.sh --jitconfig "$jit"
