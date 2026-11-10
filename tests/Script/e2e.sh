#!/usr/bin/env bash
# Triggers the jogger-e2e workflow on a real repository and watches the job through the Jogger API.
# Usage: JOGGER_URL=https://jogger.example.com JOGGER_TOKEN=jog_xxx REPO=owner/name tests/Script/e2e.sh
# Needs: gh (signed in), curl, jq. See docs/E2E.md.
set -euo pipefail

: "${JOGGER_URL:?set JOGGER_URL to the server address}"
: "${JOGGER_TOKEN:?set JOGGER_TOKEN to a Jogger API token}"
: "${REPO:?set REPO to owner/name}"
WORKFLOW="${WORKFLOW:-jogger-e2e.yml}"
TIMEOUT="${TIMEOUT:-300}"

api() { curl -fsS -H "Authorization: Bearer ${JOGGER_TOKEN}" -H "Accept: application/json" "${JOGGER_URL%/}/api/v1$1"; }

api /me >/dev/null || { echo "cannot reach the Jogger API with this token" >&2; exit 1; }

started=$(date +%s)
echo "Dispatching ${WORKFLOW} on ${REPO}…"
gh workflow run "$WORKFLOW" --repo "$REPO"

job_id=""
last=""
while :; do
  elapsed=$(( $(date +%s) - started ))
  if [ "$elapsed" -gt "$TIMEOUT" ]; then
    echo "timed out after ${TIMEOUT}s (last status: ${last:-never seen})" >&2
    exit 1
  fi

  # The newest job of this repository created after we dispatched.
  job=$(api "/jobs?repository=${REPO}&per_page=5" | jq -c --argjson since "$started" \
    '[.data[] | select((.queued_at // .created_at | fromdateiso8601) >= ($since - 5))] | first // empty')
  if [ -n "$job" ]; then
    job_id=$(jq -r .id <<<"$job")
    status=$(jq -r .status <<<"$job")
    if [ "$status" != "$last" ]; then
      echo "[$(printf '%3d' "$elapsed")s] ${status} $(jq -r '.unmatched_reason // ""' <<<"$job")"
      last="$status"
    fi
    if [ "$status" = "completed" ]; then
      conclusion=$(jq -r .conclusion <<<"$job")
      echo "Conclusion: ${conclusion}"
      echo "Queued → started: $(jq -r '((.started_at | fromdateiso8601) - (.queued_at | fromdateiso8601))' <<<"$job")s"
      echo "--- log (last 20 lines) ---"
      api "/jobs/${job_id}/logs" | tail -n 20 || echo "(no log available)"
      [ "$conclusion" = "success" ]
      exit $?
    fi
  fi
  sleep 2
done
