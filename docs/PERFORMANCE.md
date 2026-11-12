# Performance

## Targets

| Request | Target (server time, p95) |
|---------|---------------------------|
| `POST /webhooks/github` (`workflow_job` delivery) | < 200 ms |
| `GET /jobs` (Jobs index, first page, as a global admin) | < 500 ms |

GitHub itself only requires a 2xx within 10 seconds; the webhook target is ours.

## Seeded benchmark

`tests/Perf/seed-benchmark.php` checks both targets against a realistic volume of data.

```sh
docker compose -f docker/compose/docker-compose.dev.yml exec -T postgres psql -U jogger -c 'create database jogger_perf'
DB_DATABASE=jogger_perf APP_ENV=testing php tests/Perf/seed-benchmark.php
```

It takes about a minute and a half, most of it seeding. The exit code is 0 when both targets are met,
1 when one is missed, 2 when the database is not `jogger_perf`, 3 when a request did not behave as expected.

What it does:

1. **Refuses to run** unless `DB_DATABASE` is `jogger_perf`, because it runs `migrate:fresh`.
2. **Seeds** with the model factories: 1 GitHub App, 5 installations, 50 repositories, 20 pools, 300 runners,
   1 admin, and 50 000 workflow jobs (about 75 % completed, 5 % running, 20 % queued, spread over 23 days).
3. **Measures** 250 requests each, after 20 warm-up requests, through the HTTP kernel in the same PHP process:
   - the webhook, with a correctly signed `workflow_job` payload and a new delivery id each time. The full
     middleware stack runs (signature check, duplicate check), then the delivery is stored and the processing
     job dispatched. The queue uses the `null` driver, so the job is discarded: processing happens in a worker
     and is not part of the request. The script checks that all 270 deliveries were stored.
   - the Jobs index as the admin (who sees all 50 000 jobs). A response only counts if it is a 200 that
     contains links to jobs.
4. Prints p50 / p95 / max in milliseconds.

Session and cache use the `array` driver and broadcasting is off, so only PostgreSQL is needed.

## Measured

12 November 2026, one developer laptop, PHP CLI, PostgreSQL 17 in Docker on the same machine. Two runs:

| Request | p50 | p95 | max | Target (p95) |
|---------|-----|-----|-----|--------------|
| `POST /webhooks/github` | 10.0 ms | 12.8 – 13.9 ms | 17.1 ms | < 200 ms |
| `GET /jobs` | 108 – 109 ms | 132 – 152 ms | 177 ms | < 500 ms |

Both targets are met with a wide margin; no index or query had to be changed.

## What these numbers do not cover

- **One laptop, in-process.** No network, no web server (FrankenPHP), no TLS, no concurrency: requests run one
  after the other. Treat the figures as an order of magnitude, not as a capacity measurement.
- **No Redis.** In production the webhook also pushes the job to Redis, and the Jobs page reads its session
  from Redis. Each is one more local round trip per request.
- **No webhook processing.** How long a worker takes to handle a delivery is not measured.
- **Only the first, unfiltered page** of the Jobs index. Filters, search and deeper pages are not measured.
- **Admin only.** A non-admin user's list goes through team grants, which is a different query.
- The Livewire update requests (live refresh, filter changes) are not measured, only the initial page load.
