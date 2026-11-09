<?php

declare(strict_types=1);

namespace Tests\Feature\Github;

use App\Domain\Github\Contracts\GithubGateway;
use App\Domain\Github\Data\RunnerScope;
use App\Domain\Github\Enums\ScopeType;
use App\Domain\Github\Gateway\HttpGithubGateway;
use App\Domain\Github\Gateway\RateLimitTracker;
use App\Domain\Github\Models\GithubApp;
use App\Domain\Github\Models\Installation;
use App\Domain\Github\Models\Repository;
use App\Domain\Github\Testing\FakeGithubGateway;
use App\Support\Exceptions\GithubApiError;
use App\Support\Exceptions\GithubRateLimited;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class GatewayTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://api.github.com';

    private static ?string $privateKey = null;

    private Installation $installation;

    private Repository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        // Generating an RSA key is slow, so one key serves the whole class.
        self::$privateKey ??= (string) GithubApp::factory()->withRealKey()->make()->private_key;

        $app = GithubApp::factory()->create(['private_key' => self::$privateKey, 'client_id' => 'Iv1.testclient']);
        $this->installation = Installation::factory()->for($app, 'app')->create(['github_installation_id' => 4730226, 'account_login' => 'octo-org']);
        $this->repository = Repository::factory()->for($this->installation)->create(['github_repo_id' => 186853002, 'full_name' => 'octo-org/example-workflow']);
        Cache::flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path("tests/Fixtures/github/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    private function tokenResponse(string $token = 'ghs_testtoken'): PromiseInterface
    {
        return Http::response(['token' => $token, 'expires_at' => now()->addHour()->toIso8601String()], 201);
    }

    private function gateway(): GithubGateway
    {
        return app(GithubGateway::class);
    }

    private function repoScope(): RunnerScope
    {
        return new RunnerScope($this->installation, ScopeType::Repository, 'octo-org/example-workflow');
    }

    private function orgScope(?int $group = null): RunnerScope
    {
        return new RunnerScope($this->installation, ScopeType::Organization, 'octo-org', $group);
    }

    private function tokenRequests(): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), '/access_tokens'))->count();
    }

    private function requestsTo(string $needle): int
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), $needle))->count();
    }

    // ---------------------------------------------------------------- authentication

    public function test_the_default_binding_is_the_http_gateway(): void
    {
        $this->assertInstanceOf(HttpGithubGateway::class, app(GithubGateway::class));
    }

    public function test_the_installation_token_is_fetched_once_and_reused_within_its_lifetime(): void
    {
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/rate_limit' => Http::response($this->fixture('rate_limit.response')),
        ]);

        $this->gateway()->getRateLimit($this->installation);
        $this->gateway()->getRateLimit($this->installation);

        $this->assertSame(1, $this->tokenRequests());
        $this->assertSame(2, $this->requestsTo('/rate_limit'));
    }

    public function test_the_token_is_re_minted_after_it_expires_from_the_cache(): void
    {
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/rate_limit' => Http::response($this->fixture('rate_limit.response')),
        ]);

        $this->gateway()->getRateLimit($this->installation);
        $this->travel(56)->minutes();
        $this->gateway()->getRateLimit($this->installation);

        $this->assertSame(2, $this->tokenRequests());
    }

    public function test_the_cached_token_is_encrypted(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse('ghs_plain_secret'), '*/rate_limit' => Http::response($this->fixture('rate_limit.response'))]);

        $this->gateway()->getRateLimit($this->installation);

        $stored = Cache::get('github.installation_token.4730226');
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('ghs_plain_secret', $stored);
    }

    public function test_the_app_jwt_is_a_valid_short_lived_rs256_token_and_calls_use_the_installation_token(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse('ghs_abc'), '*/rate_limit' => Http::response($this->fixture('rate_limit.response'))]);

        $this->gateway()->getRateLimit($this->installation);

        $mint = Http::recorded(fn (Request $r) => str_contains($r->url(), '/access_tokens'))->first()[0];
        $this->assertSame('POST', $mint->method());
        $this->assertSame(self::API.'/app/installations/4730226/access_tokens', $mint->url());

        $jwt = substr($mint->header('Authorization')[0], strlen('Bearer '));
        $public = openssl_pkey_get_details(openssl_pkey_get_private(self::$privateKey))['key'];
        $claims = (array) JWT::decode($jwt, new Key($public, 'RS256')); // verifies the signature

        $this->assertSame('Iv1.testclient', $claims['iss']);
        $this->assertLessThanOrEqual(600, $claims['exp'] - $claims['iat']);
        $this->assertEqualsWithDelta(time() - 60, $claims['iat'], 5);
        $this->assertEquals(['alg' => 'RS256', 'typ' => 'JWT'], json_decode((string) base64_decode(explode('.', $jwt)[0]), true));

        $call = Http::recorded(fn (Request $r) => str_contains($r->url(), '/rate_limit'))->first()[0];
        $this->assertSame('Bearer ghs_abc', $call->header('Authorization')[0]);
        $this->assertSame('application/vnd.github+json', $call->header('Accept')[0]);
        $this->assertSame('2022-11-28', $call->header('X-GitHub-Api-Version')[0]);
        $this->assertNotEmpty($call->header('User-Agent')[0]);
    }

    public function test_github_enterprise_style_base_urls_are_honoured(): void
    {
        $this->installation->app->update(['api_base_url' => 'https://ghe.example.com/api/v3/']);
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/rate_limit' => Http::response($this->fixture('rate_limit.response'))]);

        $this->gateway()->getRateLimit($this->installation->fresh());

        Http::assertSent(fn (Request $r) => $r->url() === 'https://ghe.example.com/api/v3/app/installations/4730226/access_tokens');
        Http::assertSent(fn (Request $r) => $r->url() === 'https://ghe.example.com/api/v3/rate_limit');
    }

    public function test_a_401_replaces_the_token_and_retries_once(): void
    {
        Http::fake([
            '*/access_tokens' => Http::sequence()->push(['token' => 'ghs_old', 'expires_at' => now()->addHour()->toIso8601String()], 201)
                ->push(['token' => 'ghs_new', 'expires_at' => now()->addHour()->toIso8601String()], 201),
            '*/rate_limit' => Http::sequence()->push(['message' => 'Bad credentials'], 401)->push($this->fixture('rate_limit.response'), 200),
        ]);

        $limit = $this->gateway()->getRateLimit($this->installation);

        $this->assertSame(4913, $limit->remaining);
        $this->assertSame(2, $this->tokenRequests());
        $this->assertSame(2, $this->requestsTo('/rate_limit'));
    }

    public function test_persistent_401_fails_after_a_single_retry(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/rate_limit' => Http::response(['message' => 'Bad credentials'], 401)]);

        try {
            $this->gateway()->getRateLimit($this->installation);
            $this->fail('expected GithubApiError');
        } catch (GithubApiError $e) {
            $this->assertSame(401, $e->githubStatus);
            $this->assertStringContainsString('credentials', $e->getMessage());
        }

        $this->assertSame(2, $this->requestsTo('/rate_limit'));
    }

    public function test_an_unusable_private_key_is_reported_clearly_instead_of_crashing(): void
    {
        $this->installation->app->update(['private_key' => "-----BEGIN RSA PRIVATE KEY-----\ngarbage\n-----END RSA PRIVATE KEY-----"]);
        Http::fake();

        try {
            $this->gateway()->getRateLimit($this->installation->fresh());
            $this->fail('expected GithubApiError');
        } catch (GithubApiError $e) {
            $this->assertStringContainsString('cannot sign requests', $e->getMessage());
            $this->assertStringNotContainsString('BEGIN RSA', $e->getMessage()); // never echo key material
        }

        Http::assertNothingSent();
    }

    public function test_a_failed_token_mint_explains_the_problem(): void
    {
        Http::fake(['*/access_tokens' => Http::response(['message' => 'A JSON web token could not be decoded'], 401)]);

        try {
            $this->gateway()->getRateLimit($this->installation);
            $this->fail('expected GithubApiError');
        } catch (GithubApiError $e) {
            $this->assertStringContainsString('GitHub App key', $e->getMessage());
        }

        Http::fake(['*/access_tokens' => Http::response(['message' => 'Not Found'], 404)]);
        $this->expectException(GithubApiError::class);
        $this->gateway()->getRateLimit($this->installation);
    }

    // ---------------------------------------------------------------- rate limits

    public function test_a_primary_rate_limit_403_becomes_github_rate_limited_with_the_reset_delay(): void
    {
        $reset = time() + 600;
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/rate_limit' => Http::response(['message' => 'API rate limit exceeded'], 403, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) $reset]),
        ]);

        try {
            $this->gateway()->getRateLimit($this->installation);
            $this->fail('expected GithubRateLimited');
        } catch (GithubRateLimited $e) {
            $this->assertEqualsWithDelta(600, $e->retryAfterSeconds, 3);
            $this->assertSame(['Retry-After' => (string) $e->retryAfterSeconds], $e->headers());
        }
    }

    public function test_secondary_limits_use_retry_after_then_default_to_a_minute(): void
    {
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/rate_limit' => Http::sequence()
                ->push(['message' => 'You have exceeded a secondary rate limit.'], 403, ['retry-after' => '37'])
                ->push(['message' => 'You have exceeded a secondary rate limit.'], 403)
                ->push(['message' => 'Too many'], 429),
        ]);
        $tracker = app(RateLimitTracker::class);

        foreach ([37, 60, 60] as $expected) {
            Cache::forget('github.paused.4730226'); // a new request: ignore the pause the previous one set
            try {
                $this->gateway()->getRateLimit($this->installation);
                $this->fail('expected GithubRateLimited');
            } catch (GithubRateLimited $e) {
                $this->assertSame($expected, $e->retryAfterSeconds);
            }
        }
        $this->assertNotNull($tracker->pausedUntil(4730226));
    }

    public function test_after_a_rate_limit_further_calls_fail_fast_without_hitting_github(): void
    {
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/rate_limit' => Http::response(['message' => 'rate limit'], 403, ['x-ratelimit-remaining' => '0', 'x-ratelimit-reset' => (string) (time() + 300)]),
        ]);

        try {
            $this->gateway()->getRateLimit($this->installation);
        } catch (GithubRateLimited) {
        }
        $before = $this->requestsTo('/rate_limit');

        $this->expectException(GithubRateLimited::class);
        try {
            $this->gateway()->getRateLimit($this->installation);
        } finally {
            $this->assertSame($before, $this->requestsTo('/rate_limit')); // no new HTTP call
        }
    }

    public function test_the_pause_lifts_once_the_window_passes(): void
    {
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/rate_limit' => Http::sequence()
                ->push(['message' => 'limit'], 403, ['retry-after' => '30'])
                ->push($this->fixture('rate_limit.response'), 200),
        ]);

        try {
            $this->gateway()->getRateLimit($this->installation);
        } catch (GithubRateLimited) {
        }

        $this->travel(31)->seconds();

        $this->assertSame(4913, $this->gateway()->getRateLimit($this->installation)->remaining);
    }

    public function test_a_plain_403_is_a_permission_error_not_a_rate_limit(): void
    {
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/generate-jitconfig' => Http::response(['message' => 'Resource not accessible by integration'], 403, ['x-ratelimit-remaining' => '4900']),
        ]);

        try {
            $this->gateway()->generateJitConfig($this->repoScope(), 'r1', ['self-hosted']);
            $this->fail('expected GithubApiError');
        } catch (GithubApiError $e) {
            $this->assertSame(403, $e->githubStatus);
            $this->assertStringContainsString('permission', $e->getMessage());
        }
        $this->assertNull(app(RateLimitTracker::class)->pausedUntil(4730226));
    }

    public function test_remaining_quota_is_remembered_from_response_headers(): void
    {
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/rate_limit' => Http::response($this->fixture('rate_limit.response'), 200, ['x-ratelimit-remaining' => '4912', 'x-ratelimit-reset' => '1794240000']),
        ]);

        $this->gateway()->getRateLimit($this->installation);

        $this->assertSame(['remaining' => 4912, 'reset' => 1794240000], app(RateLimitTracker::class)->state(4730226));
    }

    // ---------------------------------------------------------------- JIT runners

    public function test_jit_config_for_a_repository_scope(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/generate-jitconfig' => Http::response($this->fixture('jitconfig.response'), 201)]);

        $jit = $this->gateway()->generateJitConfig($this->repoScope(), 'jogger-9f2c1a7e', ['self-hosted', 'linux', 'x64', 'jogger-large']);

        $this->assertSame(23, $jit->runnerId);
        $this->assertSame('jogger-9f2c1a7e', $jit->name);
        $this->assertStringStartsWith('YWJj', $jit->encodedJitConfig);
        Http::assertSent(function (Request $r) {
            return $r->method() === 'POST'
                && $r->url() === self::API.'/repos/octo-org/example-workflow/actions/runners/generate-jitconfig'
                && $r['name'] === 'jogger-9f2c1a7e'
                && $r['runner_group_id'] === 1
                && $r['labels'] === ['self-hosted', 'linux', 'x64', 'jogger-large'];
        });
    }

    public function test_jit_config_for_an_organization_scope_uses_its_runner_group(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/generate-jitconfig' => Http::response($this->fixture('jitconfig.response'), 201)]);

        $jit = $this->gateway()->generateJitConfig($this->orgScope(7), 'r', ['self-hosted']);

        $this->assertSame(23, $jit->runnerId);
        Http::assertSent(fn (Request $r) => $r->url() === self::API.'/orgs/octo-org/actions/runners/generate-jitconfig' && $r['runner_group_id'] === 7);
    }

    public function test_labels_are_cleaned_and_validated_before_any_request(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/generate-jitconfig' => Http::response($this->fixture('jitconfig.response'), 201)]);

        $this->gateway()->generateJitConfig($this->repoScope(), 'r', [' self-hosted ', 'linux', 'linux', '']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'generate-jitconfig') && $r['labels'] === ['self-hosted', 'linux']);

        foreach ([[], [' ', ''], array_map(fn ($i) => "l{$i}", range(1, 101))] as $bad) {
            try {
                $this->gateway()->generateJitConfig($this->repoScope(), 'r', $bad);
                $this->fail('expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1, $this->requestsTo('generate-jitconfig'));
    }

    public function test_a_duplicate_runner_name_is_reported_as_an_api_error(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/generate-jitconfig' => Http::response(['message' => 'Validation Failed'], 409)]);

        $this->expectException(GithubApiError::class);
        $this->gateway()->generateJitConfig($this->repoScope(), 'dup', ['self-hosted']);
    }

    public function test_scope_names_cannot_inject_path_segments(): void
    {
        foreach ([[ScopeType::Repository, 'octo-org'], [ScopeType::Repository, 'a/b/c'], [ScopeType::Repository, '../x/y'], [ScopeType::Organization, 'a/b'], [ScopeType::Organization, '']] as [$type, $target]) {
            try {
                new RunnerScope($this->installation, $type, $target);
                $this->fail("{$target} should be rejected");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    // ---------------------------------------------------------------- retries

    public function test_server_errors_are_retried_three_times_then_raise(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/generate-jitconfig' => Http::response(['message' => 'Bad gateway'], 502)]);

        try {
            $this->gateway()->generateJitConfig($this->repoScope(), 'r', ['self-hosted']);
            $this->fail('expected GithubApiError');
        } catch (GithubApiError $e) {
            $this->assertSame(502, $e->githubStatus);
            $this->assertStringContainsString('unavailable', $e->getMessage());
        }

        $this->assertSame(4, $this->requestsTo('generate-jitconfig')); // first attempt + 3 retries
    }

    public function test_a_server_error_that_recovers_returns_normally(): void
    {
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/generate-jitconfig' => Http::sequence()->push([], 503)->push([], 500)->push($this->fixture('jitconfig.response'), 201),
        ]);

        $jit = $this->gateway()->generateJitConfig($this->repoScope(), 'r', ['self-hosted']);

        $this->assertSame(23, $jit->runnerId);
        $this->assertSame(3, $this->requestsTo('generate-jitconfig'));
    }

    public function test_client_errors_and_rate_limits_are_never_retried(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/generate-jitconfig' => Http::response(['message' => 'Validation Failed'], 422)]);

        try {
            $this->gateway()->generateJitConfig($this->repoScope(), 'r', ['self-hosted']);
        } catch (GithubApiError) {
        }

        $this->assertSame(1, $this->requestsTo('generate-jitconfig'));
    }

    public function test_connection_failures_are_retried_then_reported(): void
    {
        $attempts = 0;
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/generate-jitconfig' => function () use (&$attempts) {
                $attempts++;

                throw new ConnectionException('cURL error 28: timed out');
            },
        ]);

        try {
            $this->gateway()->generateJitConfig($this->repoScope(), 'r', ['self-hosted']);
            $this->fail('expected GithubApiError');
        } catch (GithubApiError $e) {
            $this->assertSame(0, $e->githubStatus);
            $this->assertStringContainsString('Could not reach GitHub', $e->getMessage());
        }

        $this->assertSame(4, $attempts); // first attempt + 3 retries
    }

    // ---------------------------------------------------------------- runners

    public function test_deleting_a_runner_is_idempotent(): void
    {
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/actions/runners/23' => Http::sequence()->push('', 204)->push(['message' => 'Not Found'], 404)->push(['message' => 'forbidden'], 403),
        ]);

        $this->gateway()->deleteRunner($this->repoScope(), 23);
        $this->gateway()->deleteRunner($this->repoScope(), 23); // already gone: fine

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API.'/repos/octo-org/example-workflow/actions/runners/23');

        $this->expectException(GithubApiError::class);
        $this->gateway()->deleteRunner($this->repoScope(), 23);
    }

    public function test_deleting_an_org_runner_uses_the_org_path(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/actions/runners/9' => Http::response('', 204)]);

        $this->gateway()->deleteRunner($this->orgScope(), 9);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::API.'/orgs/octo-org/actions/runners/9');
    }

    public function test_list_runners_maps_and_filters_by_name(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/actions/runners*' => Http::response($this->fixture('runners.list.response'))]);

        $runners = $this->gateway()->listRunners($this->orgScope(), 'jogger-9f2c1a7e');

        $this->assertCount(2, $runners);
        $this->assertSame('jogger-9f2c1a7e', $runners[0]->name);
        $this->assertTrue($runners[0]->busy);
        $this->assertSame(['self-hosted', 'linux', 'jogger-large'], $runners[0]->labels);
        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), '/orgs/octo-org/actions/runners')) {
                return false;
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

            return $query['name'] === 'jogger-9f2c1a7e' && $query['per_page'] === '100' && $query['page'] === '1';
        });
    }

    public function test_pagination_follows_pages_until_a_short_page(): void
    {
        $page = fn (int $from, int $count) => array_map(fn ($i) => ['id' => $i, 'name' => "r{$i}", 'status' => 'online', 'busy' => false, 'labels' => []], range($from, $from + $count - 1));
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/actions/runners*' => Http::sequence()
                ->push(['total_count' => 250, 'runners' => $page(1, 100)])
                ->push(['total_count' => 250, 'runners' => $page(101, 100)])
                ->push(['total_count' => 250, 'runners' => $page(201, 50)]),
        ]);

        $runners = $this->gateway()->listRunners($this->repoScope());

        $this->assertCount(250, $runners);
        $this->assertSame(3, $this->requestsTo('/actions/runners'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/actions/runners') && (int) $r['page'] === 3);
    }

    public function test_pagination_stops_when_total_count_is_reached_on_a_full_page(): void
    {
        $items = array_map(fn ($i) => ['id' => $i, 'name' => "r{$i}", 'status' => 'online', 'busy' => false, 'labels' => []], range(1, 100));
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/actions/runners*' => Http::response(['total_count' => 100, 'runners' => $items])]);

        $this->assertCount(100, $this->gateway()->listRunners($this->repoScope()));
        $this->assertSame(1, $this->requestsTo('/actions/runners'));
    }

    // ---------------------------------------------------------------- repositories, jobs, rate limit

    public function test_installation_repositories_are_listed_as_data_objects(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/installation/repositories*' => Http::response($this->fixture('installation_repositories.response'))]);

        $repos = $this->gateway()->listInstallationRepositories($this->installation);

        $this->assertCount(2, $repos);
        $this->assertSame('octo-org/example-workflow', $repos[0]->fullName);
        $this->assertTrue($repos[0]->private);
        $this->assertFalse($repos[1]->private);
    }

    public function test_queued_jobs_come_from_queued_and_in_progress_runs_and_only_queued_jobs_are_kept(): void
    {
        Http::fake([
            '*/access_tokens' => $this->tokenResponse(),
            '*/actions/runs?*' => Http::response($this->fixture('workflow_runs.queued.response')),
            '*/actions/runs/29679449/jobs*' => Http::response($this->fixture('workflow_jobs.response')),
        ]);

        $jobs = $this->gateway()->listQueuedJobs($this->installation, $this->repository);

        // Both run listings return the same run; its jobs are de-duplicated and the in-progress job is dropped.
        $this->assertSame([399444496, 399444498], array_map(fn ($j) => $j->jobId, $jobs));
        $this->assertSame('queued', $jobs[0]->action);
        $this->assertSame('octo-org/example-workflow', $jobs[0]->repositoryFullName);
        $this->assertSame(4730226, $jobs[0]->installationId);
        $this->assertSame(['self-hosted', 'linux', 'jogger-large'], $jobs[0]->labels);
        $this->assertTrue($jobs[0]->isSelfHosted());
        $this->assertFalse($jobs[1]->isSelfHosted());
        $statuses = Http::recorded(fn (Request $r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/actions/runs'))->map(fn ($p) => $p[0]['status'])->values()->all();
        $this->assertSame(['queued', 'in_progress'], $statuses);
    }

    public function test_rate_limit_is_mapped(): void
    {
        Http::fake(['*/access_tokens' => $this->tokenResponse(), '*/rate_limit' => Http::response($this->fixture('rate_limit.response'))]);

        $limit = $this->gateway()->getRateLimit($this->installation);

        $this->assertSame(5000, $limit->limit);
        $this->assertSame(4913, $limit->remaining);
        $this->assertSame(87, $limit->used);
        $this->assertSame(1794240000, $limit->reset->timestamp);
    }

    // ---------------------------------------------------------------- the fake

    public function test_the_fake_gateway_can_be_installed_and_records_calls(): void
    {
        $fake = FakeGithubGateway::install();

        $this->assertSame($fake, app(GithubGateway::class));

        $jit = app(GithubGateway::class)->generateJitConfig($this->repoScope(), 'runner-a', ['self-hosted']);
        $this->assertSame('runner-a', $jit->name);
        $this->assertCount(1, $fake->listRunners($this->repoScope()));

        app(GithubGateway::class)->deleteRunner($this->repoScope(), $jit->runnerId);
        $this->assertCount(0, $fake->listRunners($this->repoScope()));
        $fake->assertCalled('generateJitConfig');
        $fake->assertCalled('deleteRunner');
        $fake->assertNotCalled('getRateLimit');
    }

    public function test_the_fake_can_simulate_failures_once(): void
    {
        $fake = FakeGithubGateway::install()->failNextWith(new GithubRateLimited('slow down', 42));

        try {
            $fake->generateJitConfig($this->repoScope(), 'r', ['self-hosted']);
            $this->fail('expected GithubRateLimited');
        } catch (GithubRateLimited $e) {
            $this->assertSame(42, $e->retryAfterSeconds);
        }

        $this->assertSame('r', $fake->generateJitConfig($this->repoScope(), 'r', ['self-hosted'])->name);
    }
}
