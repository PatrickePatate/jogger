<?php

namespace Tests\Browser;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\Test;
use Tests\DuskTestCase;

class SetupTest extends DuskTestCase
{
    use DatabaseMigrations;

    #[Test]
    public function fresh_install_goes_through_setup_then_logout_and_login(): void
    {
        $password = 'Correct-Horse-Battery-9';

        $this->browse(function (Browser $browser) use ($password) {
            $browser->visit('/')
                ->waitForLocation('/setup')
                ->type('name', 'Ada Admin')
                ->type('email', 'ada@example.test')
                ->type('password', $password)
                ->type('password_confirmation', $password)
                ->press('Create account')
                ->waitForText('Continue')
                ->press('Continue')
                ->waitForText('Skip for now')
                ->press('Skip for now')
                ->waitForLocation('/')
                ->assertPathIs('/')
                ->assertAuthenticated();

            $this->assertNoCspViolations($browser);

            $browser->script("document.querySelector('form[action$=\"/logout\"]').submit()");
            $browser->waitForLocation('/login')
                ->assertGuest()
                ->type('email', 'ada@example.test')
                ->type('password', $password)
                ->press('Sign in')
                ->waitForLocation('/')
                ->assertAuthenticated();

            $this->assertNoCspViolations($browser);
        });

        $this->assertSame(1, User::query()->count());
    }
}
