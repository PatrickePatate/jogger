# Spike: actions cache protocol (Task 54)

**Status: not performed.** The spike requires running a real runner against GitHub through a traffic-logging proxy, which needs the same real GitHub App, repository and public URL as the end-to-end run of Task 40. None of that was available, so **no endpoint or payload was observed** and nothing below is a measurement.

## What is known without capturing traffic

- `actions/cache` v4 uses the "cache service v2" (Twirp RPC `CreateCacheEntry`, `FinalizeCacheEntryUpload`, `GetCacheEntryDownloadURL` on `ACTIONS_RESULTS_URL`, with blob upload/download to signed URLs). The legacy REST service on `ACTIONS_CACHE_URL` was shut down by GitHub in 2025.
- Both variables and the `ACTIONS_RUNTIME_TOKEN` are sent to the runner **by GitHub in the job message**, not read from the container environment. Overriding them means patching the runner or intercepting its traffic. This is the main risk and is exactly what the spike was meant to verify.

These points come from public documentation and other self-hosted cache projects, not from this project's own observation; versions were not recorded.

## Recommendation: no-go for v1.0

Defer the cache service (Task 55) to v1.1. It cannot be designed responsibly without the capture, and it is flagged optional for v1.0. What v1.0 offers instead: per-pool tool-cache volumes and a capped Docker build cache per host (Task 49).

To do the spike later: run `docs/E2E.md` with the runner's `https_proxy` pointing at mitmproxy, use a workflow with `actions/cache@v4`, and record every request here with the runner and action versions. Estimated effort once a real environment exists: 4 h for the spike, 8–12 h for the service.
