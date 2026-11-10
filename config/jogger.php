<?php

declare(strict_types=1);

return [

    /*
    | Retention (days)
    */
    'log_retention_days' => (int) env('JOGGER_LOG_RETENTION_DAYS', 30),
    'job_retention_days' => (int) env('JOGGER_JOB_RETENTION_DAYS', 90),
    'delivery_retention_days' => (int) env('JOGGER_DELIVERY_RETENTION_DAYS', 14),

    /*
    | Maximum stored log size per job, in bytes (default 100 MB).
    */
    'log_max_bytes_per_job' => (int) env('JOGGER_LOG_MAX_BYTES', 104_857_600),

    /*
    | Seconds before a runner stuck in "provisioning" is reaped.
    */
    'runner_provision_timeout' => (int) env('JOGGER_PROVISION_TIMEOUT', 180),

    /*
    | Seconds without a heartbeat before a host is marked offline.
    */
    'host_offline_after' => (int) env('JOGGER_HOST_OFFLINE_AFTER', 30),

    /*
    | Seconds between reconciliation runs against GitHub.
    */
    'reconcile_interval' => (int) env('JOGGER_RECONCILE_INTERVAL', 60),

    /*
    | Bearer token protecting /metrics. Null disables the endpoint.
    */
    'metrics_token' => env('JOGGER_METRICS_TOKEN'),

    /*
    | Auto-enroll the control plane's own Docker socket as a host.
    */
    'local_host_enabled' => (bool) env('JOGGER_LOCAL_HOST', false),

    /*
    | Default runner image for new pools.
    */
    'default_runner_image' => env('JOGGER_RUNNER_IMAGE', 'ghcr.io/jogger/jogger-runner:latest'),

    /*
    | Filesystem disk holding job logs.
    */
    'log_disk' => env('JOGGER_LOG_DISK', 'logs'),

    /*
    | Experimental actions/cache-compatible service.
    */
    'cache_enabled' => (bool) env('JOGGER_CACHE_ENABLED', false),

    /*
    | GitHub API client. GitHub supports 2022-11-28 until March 2028 and also offers 2026-03-10; the
    | pinned version only changes after reading GitHub's breaking-changes list for the new one.
    */
    'github' => [
        // GitHub.com only in v1; these exist so GitHub Enterprise Server can be added later.
        'web_url' => env('JOGGER_GITHUB_WEB_URL', 'https://github.com'),
        'api_url' => env('JOGGER_GITHUB_API_URL', 'https://api.github.com'),
        'api_version' => env('JOGGER_GITHUB_API_VERSION', '2022-11-28'),
        'timeout' => (int) env('JOGGER_GITHUB_TIMEOUT', 15),
        // Retries (after the first attempt) on connection errors and 5xx responses.
        'retries' => (int) env('JOGGER_GITHUB_RETRIES', 3),
        // Base delay for exponential backoff with jitter; 0 disables sleeping (tests).
        'retry_base_ms' => (int) env('JOGGER_GITHUB_RETRY_MS', 250),
    ],

];
