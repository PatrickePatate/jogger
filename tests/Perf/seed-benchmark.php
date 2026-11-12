<?php

/**
 * Seeded performance benchmark (Task 58). Run: DB_DATABASE=jogger_perf APP_ENV=testing php tests/Perf/seed-benchmark.php
 * Refuses to run against any database other than `jogger_perf` (it runs migrate:fresh).
 * Requests go through the HTTP kernel in-process. The queue uses the `null` driver (jobs are dispatched and
 * discarded), so the webhook timing covers signature check + delivery insert + dispatch, not the processing.
 * Session and cache use the `array` driver and broadcasting is off, so no Redis or Reverb is needed.
 * See docs/PERFORMANCE.md.
 */

use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Jobs\Enums\JobConclusion;
use App\Domain\Jobs\Models\WorkflowJob;
use App\Domain\Pools\Models\RunnerPool;
use App\Domain\Runners\Enums\RunnerState;
use App\Domain\Runners\Models\Runner;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';

if (getenv('DB_DATABASE') !== 'jogger_perf') {
    fwrite(STDERR, "Refusing to run: DB_DATABASE must be 'jogger_perf'.\n");
    exit(2);
}
foreach (['QUEUE_CONNECTION' => 'null', 'BROADCAST_CONNECTION' => 'null', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'array'] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $_SERVER[$k] = $v;
}

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

if (config('database.connections.'.config('database.default').'.database') !== 'jogger_perf') {
    fwrite(STDERR, "Refusing to run: resolved database is not 'jogger_perf'.\n");
    exit(2);
}

const INSTALLATIONS = 5;
const REPOSITORIES = 50;
const POOLS = 20;
const JOBS = 50_000;
const RUNNERS = 300;
const REQUESTS = 250;
const WARMUP = 20;

$t = microtime(true);
echo "Migrating fresh...\n";
Artisan::call('migrate:fresh', ['--force' => true]);

echo "Seeding...\n";
$github = GithubApp::factory()->create(['app_id' => 837201, 'webhook_secret' => 'perf-secret']);
$installations = Installation::factory()->count(INSTALLATIONS)->create(['github_app_id' => $github->id]);
$repos = collect();
for ($i = 0; $i < REPOSITORIES; $i++) {
    $repos->push(Repository::factory()->create(['installation_id' => $installations[$i % INSTALLATIONS]->id]));
}
$pools = collect();
for ($i = 0; $i < POOLS; $i++) {
    $pools->push(RunnerPool::factory()->create(['installation_id' => $installations[$i % INSTALLATIONS]->id]));
}
for ($i = 0; $i < RUNNERS; $i++) {
    Runner::factory()->create(['pool_id' => $pools[$i % POOLS]->id, 'state' => RunnerState::Provisioning]);
}
$admin = User::factory()->admin()->create();

// Jobs: bulk insert in chunks (factory make() -> storage-ready attributes), 80% completed, 5% running, rest queued.
$now = now();
for ($chunk = 0; $chunk < JOBS / 1000; $chunk++) {
    $rows = [];
    for ($i = 0; $i < 1000; $i++) {
        $n = $chunk * 1000 + $i;
        $factory = WorkflowJob::factory();
        $factory = match (true) {
            $n % 20 === 0 => $factory->running(),
            $n % 5 === 1 => $factory,
            default => $factory->completed($n % 7 === 0 ? JobConclusion::Failure : JobConclusion::Success),
        };
        $job = $factory->make([
            'repository_id' => $repos[$n % REPOSITORIES]->id,
            'github_job_id' => 5_000_000_000 + $n,
            'queued_at' => $now->copy()->subSeconds(($n * 7) % 2_000_000),
        ]);
        $rows[] = $job->getAttributes() + ['created_at' => $now, 'updated_at' => $now];
    }
    // Multi-row insert needs identical columns in every row.
    $columns = array_keys(array_merge(...$rows));
    $rows = array_map(fn ($r) => array_merge(array_fill_keys($columns, null), ['id' => (string) Str::uuid7()], $r), $rows);
    DB::table('workflow_jobs')->insert($rows);
}
printf("Seeded in %.1fs: %d jobs, %d runners.\n", microtime(true) - $t, DB::table('workflow_jobs')->count(), DB::table('runners')->count());
DB::statement('ANALYZE');

$kernel = $app->make(HttpKernel::class);

function percentile(array $s, float $p): float
{
    sort($s);

    return $s[(int) max(0, ceil($p / 100 * count($s)) - 1)];
}

function measure(string $label, callable $once): array
{
    for ($i = 0; $i < WARMUP; $i++) {
        $once($i);
    }
    $times = [];
    for ($i = 0; $i < REQUESTS; $i++) {
        $s = hrtime(true);
        $status = $once(WARMUP + $i);
        $times[] = (hrtime(true) - $s) / 1e6;
        if ($status !== 200) {
            fwrite(STDERR, "$label: unexpected HTTP $status (or unexpected body)\n");
            exit(3);
        }
    }
    $r = ['p50' => percentile($times, 50), 'p95' => percentile($times, 95), 'max' => max($times)];
    printf("%-14s n=%d  p50=%.1f ms  p95=%.1f ms  max=%.1f ms\n", $label, REQUESTS, $r['p50'], $r['p95'], $r['max']);

    return $r;
}

$webhook = measure('webhook', function (int $i) use ($kernel) {
    $body = json_encode([
        'action' => 'queued',
        'workflow_job' => ['id' => 9_000_000_000 + $i, 'run_id' => 1, 'name' => 'build', 'status' => 'queued', 'labels' => ['self-hosted', 'linux']],
        'repository' => ['id' => 1, 'full_name' => 'acme/api'],
        'installation' => ['id' => 1],
    ]);
    $request = Request::create('/webhooks/github', 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'workflow_job',
        'HTTP_X_GITHUB_DELIVERY' => sprintf('00000000-0000-4000-8000-%012d', $i),
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'perf-secret'),
        'HTTP_X_GITHUB_HOOK_INSTALLATION_TARGET_ID' => '837201',
        'HTTP_X_GITHUB_HOOK_INSTALLATION_TARGET_TYPE' => 'integration',
        'HTTP_USER_AGENT' => 'GitHub-Hookshot/perf',
    ], $body);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response->getStatusCode();
});

$jobs = measure('jobs-index', function (int $i) use ($kernel, $admin) {
    Auth::guard('web')->setUser($admin);
    $request = Request::create('/jobs', 'GET');
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    // A page without any link to a job detail is not the list we mean to time.
    return preg_match('#/jobs/[0-9a-f]{8}-#', (string) $response->getContent()) ? $response->getStatusCode() : 0;
});

if (($stored = DB::table('webhook_deliveries')->count()) !== WARMUP + REQUESTS) {
    fwrite(STDERR, 'webhook: expected '.(WARMUP + REQUESTS)." stored deliveries, found $stored\n");
    exit(3);
}

$fail = $webhook['p95'] >= 200 || $jobs['p95'] >= 500;
echo $fail ? "FAIL: target missed (webhook p95 < 200 ms, jobs index p95 < 500 ms).\n" : "OK: both targets met.\n";
exit($fail ? 1 : 0);
