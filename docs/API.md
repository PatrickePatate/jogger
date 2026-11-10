# HTTP API

Jogger has a small JSON API under `/api/v1` for scripts and dashboards. The machine-readable description is [openapi.yaml](openapi.yaml).

## Authentication

Create a token in Jogger under your user menu → **API tokens**. Pick its access level (Viewer, Operator, Admin; never more than your own) and an expiry. The token is shown once.

Send it as a bearer token:

```bash
curl -H "Authorization: Bearer $JOGGER_TOKEN" https://ci.example.com/api/v1/me
```

A token sees exactly what its owner sees. Calls that change something need a token with at least the Operator level, and its owner must be an operator of the resource.

Limit: 120 requests per minute per token. Beyond it the API answers `429` with a `Retry-After` header.

## Endpoints

| Method and path | Purpose | Level |
|---|---|---|
| `GET /api/v1/me` | The token's owner and level | Viewer |
| `GET /api/v1/jobs` | Jobs, newest first. Filters: `repository` (`owner/name`), `status` (`waiting`, `queued`, `in_progress`, `completed`), `pool` (id), `per_page` | Viewer |
| `GET /api/v1/jobs/{id}` | One job | Viewer |
| `GET /api/v1/jobs/{id}/logs` | The job's log as plain text; `?format=gz` for gzip | Viewer |
| `GET /api/v1/runners` | Live runners by default. Filters: `state`, `pool`, `per_page` | Viewer |
| `DELETE /api/v1/runners/{id}` | Terminate a runner. A busy runner needs `?force=true` (its job is killed), otherwise `409` | Operator |
| `GET /api/v1/hosts` | Hosts, except revoked ones | Viewer |
| `GET /api/v1/pools` | Pools | Viewer |
| `GET /api/v1/pools/{id}` | One pool | Viewer |
| `POST /api/v1/pools` | Create a pool | Operator |
| `PATCH /api/v1/pools/{id}` | Change a pool; `"status": "paused"` or `"active"` pauses or resumes it | Operator |

## Responses

A single resource is returned under `data`; lists add `links` and `meta` for paging. Jobs and runners use cursor paging: follow `links.next` until it is `null`. `per_page` is at most 100.

```json
{
  "data": [
    {
      "id": "0193a1c5-...",
      "repository": "acme/api",
      "job_name": "build",
      "labels": ["self-hosted", "linux", "x64"],
      "status": "completed",
      "conclusion": "success",
      "pool_id": "0193a1c4-...",
      "queued_at": "2026-11-10T12:10:01+00:00",
      "started_at": "2026-11-10T12:10:09+00:00",
      "completed_at": "2026-11-10T12:12:30+00:00"
    }
  ],
  "links": { "next": "https://ci.example.com/api/v1/jobs?cursor=..." },
  "meta": { "per_page": 50 }
}
```

## Errors

Errors always have the same shape. Quote `request_id` when asking for help; it is in the server logs.

```json
{
  "error": {
    "code": "VALIDATION_ERROR",
    "message": "The given data was invalid.",
    "details": { "errors": { "labels": ["The labels field is required."] } },
    "request_id": "01JC..."
  }
}
```

| Status | `code` | Meaning |
|---|---|---|
| 401 | `UNAUTHENTICATED` | Missing, unknown or expired token |
| 403 | `FORBIDDEN` | The token's level, or its owner's role on the resource, is not enough |
| 404 | `NOT_FOUND` | Not found, or not visible to the token's owner |
| 409 | `CONFLICT` | The action conflicts with the current state (for example terminating a busy runner without `force`) |
| 422 | `VALIDATION_ERROR` | Validation failed; `details.errors` lists the fields |
| 429 | `TOO_MANY_REQUESTS` | Rate limit reached |

## Example: create a pool

```bash
curl -X POST https://ci.example.com/api/v1/pools \
  -H "Authorization: Bearer $JOGGER_TOKEN" -H "Content-Type: application/json" \
  -d '{
    "installation_id": "0193a1c0-...",
    "name": "linux-small",
    "scope": "repository",
    "scope_target": "acme/api",
    "labels": ["self-hosted", "linux", "x64"],
    "image": "ghcr.io/patrickepatate/jogger-runner:latest",
    "max_runners": 5
  }'
```

A pool for a public repository must also send `"confirm_public": true`.

## Other endpoints

- `GET /metrics`: Prometheus metrics, with the `JOGGER_METRICS_TOKEN` bearer token. See [CONFIGURATION.md](CONFIGURATION.md).
- `GET /up`: liveness. `GET /health`: dependency report for signed-in administrators.
