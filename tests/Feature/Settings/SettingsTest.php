<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Domain\Access\Audit;
use App\Domain\Access\Models\AuditLog;
use App\Domain\Github\Models\WebhookDelivery;
use App\Domain\Settings\Settings;
use App\Livewire\Admin\AuditLog as AuditPage;
use App\Livewire\Settings\General;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_retention_takes_effect_on_the_next_prune_and_is_audited(): void
    {
        $old = WebhookDelivery::factory()->create(['received_at' => now()->subDays(5)]);
        $this->artisan('jogger:prune');
        $this->assertModelExists($old); // default: 14 days

        Livewire::actingAs(User::factory()->admin()->create())->test(General::class)
            ->assertSet('form.delivery_retention_days', 14)
            ->set('form.delivery_retention_days', 0)->call('save')->assertHasErrors('form.delivery_retention_days')
            ->set('form.delivery_retention_days', 3)->call('save')->assertHasNoErrors();

        $audit = AuditLog::query()->where('action', 'settings.updated')->firstOrFail();
        $this->assertEquals([14, 3], $audit->metadata['changes']['delivery_retention_days']);

        // A later process starts from the plain config and reads the saved value.
        config(['jogger.delivery_retention_days' => 14]);
        app(Settings::class)->apply();
        $this->artisan('jogger:prune');
        $this->assertModelMissing($old);
    }

    public function test_non_admins_are_refused(): void
    {
        $member = User::factory()->create();
        Livewire::actingAs($member)->test(General::class)->assertForbidden();
        Livewire::actingAs($member)->test(AuditPage::class)->assertForbidden();
        $this->actingAs($member)->get('/admin/settings')->assertForbidden();
    }

    public function test_the_audit_log_filters_and_exports_what_is_filtered(): void
    {
        $alice = User::factory()->admin()->create(['email' => 'alice@example.com']);
        $bob = User::factory()->admin()->create(['email' => 'bob@example.com']);
        Audit::record('pool.created', null, ['name' => 'alpha'], $alice);
        Audit::record('pool.deleted', null, ['name' => 'beta'], $alice);
        Audit::record('pool.created', null, ['name' => 'gamma'], $bob);
        $this->travel(-10)->days();
        Audit::record('pool.created', null, ['name' => 'ancient'], $alice);
        $this->travelBack();

        $page = Livewire::actingAs($alice)->test(AuditPage::class)
            ->assertSee('alpha')->assertSee('gamma')->assertSee('ancient')
            ->set('actor', $alice->id)->assertDontSee('gamma')->assertSee('beta')
            ->set('action', 'pool.created')->assertDontSee('beta')->assertSee('ancient')
            ->set('from', now()->subDay()->format('Y-m-d'))->assertDontSee('ancient')->assertSee('alpha');

        $csv = base64_decode($page->call('export')->assertFileDownloaded('jogger-audit-log.csv')->effects['download']['content']);
        $this->assertStringContainsString('alpha', $csv);
        $this->assertStringContainsString('alice@example.com', $csv);
        foreach (['beta', 'gamma', 'ancient'] as $absent) {
            $this->assertStringNotContainsString($absent, $csv);
        }
    }
}
