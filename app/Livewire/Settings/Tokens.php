<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Domain\Access\Actions\CreateApiToken;
use App\Domain\Access\Actions\RevokeApiToken;
use App\Domain\Access\Enums\Role;
use App\Domain\Access\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('components.layouts.app')]
#[Title('API tokens')]
final class Tokens extends Component
{
    public string $name = '';

    public string $role = 'viewer';

    /** Days until expiry; 0 = never. */
    public int $expiresInDays = 90;

    /** The plain token, shown exactly once right after creation. */
    public ?string $newToken = null;

    public function create(CreateApiToken $createToken): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'role' => ['required', Rule::enum(Role::class)],
            'expiresInDays' => ['required', 'integer', Rule::in([0, 30, 90, 365])],
        ]);

        try {
            [$plain] = $createToken(
                $this->user(),
                trim($this->name),
                Role::from($this->role),
                $this->expiresInDays > 0 ? now()->addDays($this->expiresInDays) : null,
            );
        } catch (AuthorizationException $e) {
            throw ValidationException::withMessages(['role' => $e->getMessage()]);
        }

        $this->newToken = $plain;
        $this->reset('name');
        $this->dispatch('toast', type: 'success', message: 'Token created. Copy it now, it will not be shown again.');
    }

    public function dismissToken(): void
    {
        $this->newToken = null;
    }

    public function revoke(string $tokenId, RevokeApiToken $revoke): void
    {
        $token = PersonalAccessToken::query()->findOrFail($tokenId);
        $this->authorize('delete', $token);

        $revoke($token);

        $this->dispatch('toast', type: 'success', message: 'Token revoked.');
    }

    public function render(): View
    {
        $user = $this->user();
        $ceiling = $user->maxRole();

        return view('livewire.settings.tokens', [
            'tokens' => $user->accessTokens()->latest()->get(),
            'roles' => array_values(array_filter(
                Role::cases(),
                fn (Role $r) => $r === Role::Viewer || ($ceiling !== null && $ceiling->atLeast($r)),
            )),
        ]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
