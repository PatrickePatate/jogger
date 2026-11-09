<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequestId;
use App\Support\Exceptions\GithubRateLimited;
use App\Support\Exceptions\PoolAtCapacity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorFormatTest extends TestCase
{
    public function test_unknown_api_route_returns_json_not_found_with_request_id(): void
    {
        $response = $this->getJson('/api/v1/nope');

        $response->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND')
            ->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);

        $this->assertSame($response->json('error.request_id'), $response->headers->get(RequestId::HEADER));
        $this->assertStringStartsWith('req_', $response->json('error.request_id'));
    }

    public function test_plain_get_to_api_path_also_renders_json(): void
    {
        $this->get('/api/v1/nope')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_agent_path_uses_the_same_format(): void
    {
        $this->get('/agent/v1/nope')->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_pool_at_capacity_maps_to_409(): void
    {
        Route::get('/api/v1/_test/capacity', fn () => throw new PoolAtCapacity('Pool has reached max_runners (10).', ['pool_id' => 'p1']));

        $this->getJson('/api/v1/_test/capacity')
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'POOL_LIMIT_EXCEEDED')
            ->assertJsonPath('error.message', 'Pool has reached max_runners (10).')
            ->assertJsonPath('error.details.pool_id', 'p1');
    }

    public function test_rate_limit_exception_sets_retry_after(): void
    {
        Route::get('/api/v1/_test/ratelimit', fn () => throw new GithubRateLimited(retryAfterSeconds: 42));

        $this->getJson('/api/v1/_test/ratelimit')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '42')
            ->assertJsonPath('error.code', 'GITHUB_RATE_LIMITED');
    }

    public function test_validation_errors_use_the_error_envelope(): void
    {
        Route::post('/api/v1/_test/validate', function (Request $request) {
            $request->validate(['name' => 'required']);
        });

        $this->postJson('/api/v1/_test/validate', [])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['details' => ['errors' => ['name']]]]);
    }

    public function test_unexpected_exception_is_hidden_when_not_debugging(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/v1/_test/boom', fn () => throw new \RuntimeException('secret detail'));

        $this->getJson('/api/v1/_test/boom')
            ->assertStatus(500)
            ->assertJsonPath('error.code', 'INTERNAL_ERROR')
            ->assertJsonPath('error.message', 'Something went wrong.');
    }

    public function test_inbound_request_id_is_reused_only_when_safe(): void
    {
        $ok = $this->getJson('/api/v1/nope', [RequestId::HEADER => 'trace-123']);
        $this->assertSame('trace-123', $ok->headers->get(RequestId::HEADER));

        $bad = $this->getJson('/api/v1/nope', [RequestId::HEADER => 'bad id with spaces']);
        $this->assertStringStartsWith('req_', (string) $bad->headers->get(RequestId::HEADER));
    }
}
