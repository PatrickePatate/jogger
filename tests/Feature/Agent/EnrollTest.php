<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Domain\Hosts\Actions\CreateEnrollmentToken;
use App\Domain\Hosts\Enums\HostStatus;
use App\Domain\Hosts\Models\EnrollmentToken;
use App\Domain\Hosts\Models\Host;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class EnrollTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: string, 1: EnrollmentToken}
     */
    private function token(?string $name = null): array
    {
        return app(CreateEnrollmentToken::class)(User::factory()->admin()->create(), $name);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function enroll(string $token, array $extra = []): TestResponse
    {
        return $this->postJson('/agent/v1/enroll', ['token' => $token, 'hostname' => 'build-01'] + $extra);
    }

    public function test_token_is_stored_hashed_and_expires_in_an_hour(): void
    {
        [$plain, $token] = $this->token();

        $this->assertStringStartsWith('jhe_', $plain);
        $this->assertSame(hash('sha256', $plain), $token->token_hash);
        $this->assertSame(0, DB::table('enrollment_tokens')->where('token_hash', $plain)->count());
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($token->expires_at, true), 1);
    }

    public function test_enroll_returns_credential_and_reverb_info_and_stores_only_the_hash(): void
    {
        [$plain] = $this->token();

        $response = $this->enroll($plain, ['facts' => ['arch' => 'arm64', 'os' => 'linux', 'cpu_total' => 4, 'memory_total_mb' => 8192, 'agent_version' => '0.1.0']])
            ->assertCreated()
            ->assertJsonStructure(['host_id', 'name', 'credential', 'reverb' => ['host', 'port', 'scheme', 'key']]);

        $credential = $response->json('credential');
        $host = Host::query()->findOrFail($response->json('host_id'));
        $this->assertSame(hash('sha256', $credential), $host->credential_hash);
        $this->assertNull(DB::table('hosts')->where('credential_hash', $credential)->first());
        $this->assertSame(HostStatus::Pending, $host->status);
        $this->assertSame('arm64', $host->arch);
        $this->assertSame(8192, $host->memory_total_mb);
        $this->assertNotNull(EnrollmentToken::query()->sole()->used_at);
        $this->assertArrayNotHasKey('credential_hash', $host->toArray());
    }

    public function test_a_token_can_only_be_used_once(): void
    {
        [$plain] = $this->token();

        $this->enroll($plain)->assertCreated();
        $this->enroll($plain)->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_ENROLLMENT_TOKEN');

        $this->assertSame(1, Host::query()->count());
    }

    public function test_expired_and_unknown_tokens_are_refused(): void
    {
        [$plain, $token] = $this->token();
        $token->forceFill(['expires_at' => now()->subSecond()])->save();

        $this->enroll($plain)->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_ENROLLMENT_TOKEN');
        $this->enroll('jhe_nope')->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_ENROLLMENT_TOKEN');
        $this->assertSame(0, Host::query()->count());
    }

    public function test_the_token_may_preset_the_host_name_and_names_stay_unique(): void
    {
        [$a] = $this->token('runner-box');
        [$b] = $this->token('runner-box');

        $first = $this->enroll($a)->assertCreated()->json('name');
        $second = $this->enroll($b)->assertCreated()->json('name');

        $this->assertSame('runner-box', $first);
        $this->assertNotSame($first, $second);
        $this->assertStringStartsWith('runner-box-', $second);
    }

    public function test_enroll_validates_input(): void
    {
        $this->postJson('/agent/v1/enroll', [])->assertUnprocessable()->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_the_credential_authenticates_until_the_host_is_revoked(): void
    {
        [$plain] = $this->token();
        $credential = $this->enroll($plain)->json('credential');
        $host = Host::query()->sole();

        $this->withToken($credential)->getJson('/agent/v1/me')->assertOk()->assertJsonPath('host_id', $host->id);

        $host->forceFill(['status' => HostStatus::Revoked, 'revoked_at' => now()])->save();

        $this->withToken($credential)->getJson('/agent/v1/me')->assertUnauthorized()->assertJsonPath('error.code', 'INVALID_AGENT_CREDENTIAL');
    }

    public function test_deleted_hosts_and_bad_credentials_are_refused(): void
    {
        $this->getJson('/agent/v1/me')->assertUnauthorized();
        $this->withToken('jha_wrong')->getJson('/agent/v1/me')->assertUnauthorized();
        $this->withToken('jog_'.str_repeat('a', 40))->getJson('/agent/v1/me')->assertUnauthorized();

        [$plain] = $this->token();
        $credential = $this->enroll($plain)->json('credential');
        Host::query()->sole()->delete();

        $this->withToken($credential)->getJson('/agent/v1/me')->assertUnauthorized();
    }

    public function test_enrollment_is_audited(): void
    {
        [$plain] = $this->token();
        $this->enroll($plain)->assertCreated();

        $this->assertDatabaseHas('audit_logs', ['action' => 'host.enrollment_token_created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'host.enrolled']);
    }
}
