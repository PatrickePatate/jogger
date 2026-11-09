<?php

declare(strict_types=1);

namespace App\Domain\Access\Actions;

use App\Models\User;

/** What should happen after GitHub vouched for an identity. */
final readonly class GithubSignInOutcome
{
    private function __construct(public string $kind, public ?User $user = null, public ?string $message = null) {}

    /** The identity is already linked to this account: sign in. */
    public static function signIn(User $user): self
    {
        return new self('sign_in', $user);
    }

    /** A matching account exists but the link must first be confirmed with its password. */
    public static function confirmWithPassword(User $user): self
    {
        return new self('confirm_password', $user);
    }

    /** An invitation was waiting: the account was just created. */
    public static function joined(User $user): self
    {
        return new self('joined', $user);
    }

    public static function refused(string $message): self
    {
        return new self('refused', null, $message);
    }

    public function isRefusal(): bool
    {
        return $this->kind === 'refused';
    }
}
