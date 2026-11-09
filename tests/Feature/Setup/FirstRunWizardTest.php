<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use App\Domain\Access\Actions\CreateInitialAdmin;
use App\Livewire\Setup\FirstRun;
use App\Models\User;
use App\Support\Exceptions\SetupAlreadyComplete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class FirstRunWizardTest extends TestCase
{
    use RefreshDatabase;

    private function fillValid(Testable $component): Testable
    {
        return $component
            ->set('name', 'Ada Admin')
            ->set('email', '  Ada@Example.COM ')
            ->set('password', 'a-long-enough-password')
            ->set('password_confirmation', 'a-long-enough-password');
    }

    public function test_creating_the_admin_logs_them_in_and_moves_to_the_url_check(): void
    {
        $component = $this->fillValid(Livewire::test(FirstRun::class))->call('createAdmin');

        $component->assertHasNoErrors()->assertSet('step', 2)->assertSee('Check the application URL')->assertSee('ada@example.com');

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertTrue($user->isAdmin());
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_validation_errors_are_shown_and_nothing_is_created(): void
    {
        Livewire::test(FirstRun::class)
            ->set('name', '')->set('email', 'not-an-email')->set('password', 'short')->set('password_confirmation', 'different')
            ->call('createAdmin')
            ->assertHasErrors(['name', 'email', 'password'])
            ->assertSet('step', 1);

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_the_action_refuses_a_second_administrator(): void
    {
        $create = app(CreateInitialAdmin::class);
        $create('First', 'first@example.com', 'a-long-enough-password');

        $this->expectException(SetupAlreadyComplete::class);
        try {
            $create('Second', 'second@example.com', 'a-long-enough-password');
        } finally {
            $this->assertSame(1, User::count());
        }
    }

    public function test_a_late_submission_after_someone_else_finished_setup_sends_you_to_login(): void
    {
        $component = $this->fillValid(Livewire::test(FirstRun::class));
        User::factory()->create(['email' => 'someone@example.com']); // another visitor won the race

        $component->call('createAdmin')->assertRedirect(route('login'));

        $this->assertSame(1, User::count());
        $this->assertGuest();
    }

    public function test_url_check_warns_when_app_url_differs_from_the_address_in_use(): void
    {
        config(['app.url' => 'https://jogger.example.com']);

        $this->fillValid(Livewire::test(FirstRun::class))->call('createAdmin')
            ->assertSee('These differ')
            ->assertSee('https://jogger.example.com');
    }

    public function test_url_check_warns_about_http_even_when_it_matches(): void
    {
        config(['app.url' => 'http://localhost:8000']); // the test request's origin comes from the booted .env value

        $this->fillValid(Livewire::test(FirstRun::class))->call('createAdmin')
            ->assertDontSee('These differ')
            ->assertSee('not HTTPS');
    }

    public function test_the_wizard_can_be_completed_and_github_is_skippable(): void
    {
        $component = $this->fillValid(Livewire::test(FirstRun::class))->call('createAdmin');

        $component->call('continueFromUrlCheck')->assertSet('step', 3)->assertSee('Connect GitHub')->assertSee('Optional');
        $component->call('finish')->assertRedirect(route('overview'));
    }

    public function test_steps_two_and_three_need_the_new_admin_session(): void
    {
        $component = $this->fillValid(Livewire::test(FirstRun::class))->call('createAdmin');
        auth()->logout();

        $component->call('continueFromUrlCheck')->assertForbidden();
    }

    public function test_steps_cannot_be_skipped_ahead(): void
    {
        Livewire::test(FirstRun::class)->call('finish')->assertForbidden();
        Livewire::test(FirstRun::class)->call('continueFromUrlCheck')->assertForbidden();
    }

    public function test_stepper_reflects_progress(): void
    {
        $component = Livewire::test(FirstRun::class)->assertSee('0 of 3');
        $this->fillValid($component)->call('createAdmin')->assertSee('1 of 3');
        $component->call('continueFromUrlCheck')->assertSee('2 of 3');
    }
}
