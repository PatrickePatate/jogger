<?php

declare(strict_types=1);

use App\Domain\Cloud\Drivers\HetznerDriver;
use App\Domain\Cloud\Testing\FakeCloudDriver;

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
    'local_host_token' => env('JOGGER_LOCAL_HOST_TOKEN'),

    /*
    | Docker build cache each host may keep, in GB; the daily host prune trims it down to this. 0 leaves it alone.
    */
    'build_cache_max_gb' => (float) env('JOGGER_BUILD_CACHE_MAX_GB', 20),

    /*
    | Alerting: when conditions count as a problem, and how long the same alert stays quiet after a notice.
    */
    'alerts' => [
        'dedupe_minutes' => (int) env('JOGGER_ALERT_DEDUPE_MINUTES', 60),
        'disk_free_gb' => (int) env('JOGGER_ALERT_DISK_FREE_GB', 10),
        'webhook_failures' => (int) env('JOGGER_ALERT_WEBHOOK_FAILURES', 5),
        'provision_failures' => (int) env('JOGGER_ALERT_PROVISION_FAILURES', 3),
    ],

    /*
    | Send the Content-Security-Policy as report-only (to diagnose a blocked script without breaking the page).
    */
    'csp_report_only' => (bool) env('JOGGER_CSP_REPORT_ONLY', false),

    /*
    | Where browsers reach Reverb. Empty values mean "the page's own host / port / scheme".
    */
    'reverb_public' => [
        'host' => env('REVERB_PUBLIC_HOST'),
        'port' => env('REVERB_PUBLIC_PORT'),
        'scheme' => env('REVERB_PUBLIC_SCHEME'),
    ],

    /*
    | Default runner image for new pools.
    */
    'default_runner_image' => env('JOGGER_RUNNER_IMAGE', 'ghcr.io/patrickepatate/jogger-runner:latest'),

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

    /*
    | Cloud hosts. Each driver name is the value of a provider's `driver` column. The "fake" driver is
    | test-only: it keeps servers in memory and creates nothing at any provider.
    */
    'cloud' => [
        'drivers' => [
            'fake' => FakeCloudDriver::class,
            'hetzner' => HetznerDriver::class,
        ],
        // Written into every managed server's labels; reconciliation only touches servers carrying it.
        'instance_id' => env('JOGGER_INSTANCE_ID'),
        // Global cap on cloud hosts across all templates.
        'max_hosts' => (int) env('JOGGER_CLOUD_MAX_HOSTS', 10),
        'agent_image' => env('JOGGER_AGENT_IMAGE', 'ghcr.io/patrickepatate/jogger-agent:latest'),
        'boot_timeout_seconds' => 300,
    ],

];
