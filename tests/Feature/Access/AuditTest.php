<?php

declare(strict_types=1);

namespace Tests\Feature\Access;

use App\Domain\Access\Actions\CreateApiToken;
use App\Domain\Access\Actions\CreateInitialAdmin;
use App\Domain\Access\Actions\RevokeApiToken;
use App\Domain\Access\Audit;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\AuditLog;
use App\Livewire\Settings\Security;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Fortify;
use Livewire\Livewire;
use LogicException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_stores_actor_target_metadata_and_ip(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        $this->actingAs($actor);

        $entry = Audit::record('user.role_changed', $target, ['from' => 'viewer', 'to' => 'operator']);

        $row = AuditLog::findOrFail($entry->id);
        $this->assertSame($actor->id, $row->user_id);
        $this->assertSame('user.role_changed', $row->action);
        $this->assertSame('User', $row->target_type);
        $this->assertSame($target->id, $row->target_id);
        $this->assertEquals(['from' => 'viewer', 'to' => 'operator'], $row->metadata); // jsonb does not preserve key order
        $this->assertNotNull($row->created_at);
    }

    public function test_actor_can_be_explicit_or_absent(): void
    {
        $user = User::factory()->create();

        $this->assertSame($user->id, Audit::record('x.explicit', null, [], $user)->user_id);
        $this->assertNull(Audit::record('x.system')->user_id);
    }

    public function test_entries_are_immutable_and_survive_user_deletion_as_orphans(): void
    {
        $user = User::factory()->create();
        $entry = Audit::record('x.created', null, [], $user);

        $this->expectException(LogicException::class);
        try {
            $entry->update(['action' => 'tampered']);
        } finally {
            $user->forceDelete();
            $this->assertNull($entry->fresh()->user_id); // nullOnDelete: history is kept
        }
    }

    public function test_initial_admin_creation_is_audited_with_the_new_admin_as_actor(): void
    {
        $admin = app(CreateInitialAdmin::class)('Ada', 'ada@example.com', 'a-long-enough-password');

        $row = AuditLog::where('action', 'setup.admin_created')->firstOrFail();
        $this->assertSame($admin->id, $row->user_id);
        $this->assertSame($admin->id, $row->target_id);
        $this->assertSame(['email' => 'ada@example.com'], $row->metadata);
    }

    public function test_two_factor_changes_are_audited(): void
    {
        $user = User::factory()->create();
        $component = Livewire::actingAs($user)->test(Security::class)
            ->set('password', 'password')->call('enableTwoFactor');
        $secret = Fortify::currentEncrypter()->decrypt($user->refresh()->two_factor_secret);

        $component->set('code', (new Google2FA)->getCurrentOtp($secret))->call('confirmTwoFactor')
            ->set('password', 'password')->call('regenerateRecoveryCodes')
            ->set('password', 'password')->call('disableTwoFactor');

        $this->assertSame(
            ['auth.two_factor_setup_started', 'auth.two_factor_enabled', 'auth.recovery_codes_regenerated', 'auth.two_factor_disabled'],
            AuditLog::where('user_id', $user->id)->orderBy('id')->pluck('action')->all(),
        );
    }

    public function test_failed_two_factor_attempts_leave_no_audit_row(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)->test(Security::class)->set('password', 'wrong')->call('enableTwoFactor');

        $this->assertSame(0, AuditLog::count());
    }

    public function test_token_lifecycle_is_audited_without_secrets(): void
    {
        $user = User::factory()->admin()->create();
        [$plain, $token] = app(CreateApiToken::class)($user, 'CI', Role::Operator, now()->addDays(30));
        app(RevokeApiToken::class)($token);

        $rows = AuditLog::where('target_id', $token->id)->orderBy('id')->get();
        $this->assertSame(['token.created', 'token.revoked'], $rows->pluck('action')->all());
        $this->assertSame('CI', $rows[0]->metadata['name']);
        $this->assertSame('operator', $rows[0]->metadata['role']);
        $this->assertStringNotContainsString($plain, json_encode($rows->toArray()));
    }
}
