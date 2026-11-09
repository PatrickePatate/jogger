<?php

declare(strict_types=1);

namespace App\Livewire\Setup;

use App\Domain\Access\Actions\CreateInitialAdmin;
use App\Support\Exceptions\SetupAlreadyComplete;
use App\Support\Ui\StepData;
use App\Support\Ui\StepState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * First-run wizard: 1. create the admin, 2. check the app URL, 3. connect GitHub (skippable).
 * Steps 2 and 3 run in the same page load, so reloading after step 1 leaves the wizard (setup is complete).
 */
#[Layout('components.layouts.guest', ['wide' => true])]
#[Title('Set up Jogger')]
final class FirstRun extends Component
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    #[Locked]
    public int $step = 1;

    /** Origin the browser used to reach this page (informational). */
    #[Locked]
    public string $browserOrigin = '';

    public function mount(): void
    {
        $this->browserOrigin = request()->getSchemeAndHttpHost();
    }

    public function createAdmin(CreateInitialAdmin $createAdmin): mixed
    {
        abort_unless($this->step === 1, 403);

        $this->email = mb_strtolower(trim($this->email));

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::default(), 'confirmed'],
        ]);

        try {
            $user = $createAdmin($this->name, $this->email, $this->password);
        } catch (SetupAlreadyComplete) {
            session()->flash('status', 'Jogger is already set up. Please sign in.');

            return $this->redirectRoute('login', navigate: false);
        }

        Auth::login($user);
        session()->regenerate();

        $this->reset('password', 'password_confirmation');
        $this->step = 2;

        return null;
    }

    public function continueFromUrlCheck(): void
    {
        $this->requireAdmin();
        abort_unless($this->step === 2, 403);

        $this->step = 3;
    }

    public function finish(): mixed
    {
        $this->requireAdmin();

        return $this->redirectRoute('overview', navigate: false);
    }

    /**
     * Compare APP_URL (used for webhook and OAuth callback URLs) with the URL actually in use.
     *
     * @return array{configured: string, browser: string, matches: bool, https: bool}
     */
    public function urlCheck(): array
    {
        $configured = rtrim((string) config('app.url'), '/');
        $browser = rtrim($this->browserOrigin, '/');

        return [
            'configured' => $configured,
            'browser' => $browser,
            'matches' => strcasecmp($configured, $browser) === 0,
            'https' => str_starts_with($configured, 'https://'),
        ];
    }

    /**
     * @return list<StepData>
     */
    public function steps(): array
    {
        $state = fn (int $n): StepState => match (true) {
            $this->step > $n => StepState::Done,
            $this->step === $n => StepState::Active,
            default => StepState::Pending,
        };
        $url = $this->urlCheck();

        return [
            new StepData('Create the administrator', $this->step > 1 ? Auth::user()?->email : null, $state(1)),
            new StepData('Check the application URL', $this->step > 2 ? ($url['matches'] ? 'Matches APP_URL' : 'Differs from APP_URL') : null, $state(2)),
            new StepData('Connect GitHub', $this->step > 3 ? null : 'Optional, you can do this later', $state(3)),
        ];
    }

    public function render(): View
    {
        return view('livewire.setup.first-run', [
            'steps' => $this->steps(),
            'url' => $this->step === 2 ? $this->urlCheck() : null,
        ]);
    }

    private function requireAdmin(): void
    {
        abort_unless(Auth::check() && Auth::user()->isAdmin(), 403);
    }
}
