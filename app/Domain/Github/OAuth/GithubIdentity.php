<?php

declare(strict_types=1);

namespace App\Domain\Github\OAuth;

/** Who GitHub says just signed in. Only *verified* email addresses are ever trusted for matching. */
final readonly class GithubIdentity
{
    /**
     * @param  array<int, string>  $verifiedEmails  lower-cased, verified by GitHub
     * @param  bool  $emailsReadable  false when GitHub refused to share emails (the app lacks the "Email addresses" permission)
     */
    public function __construct(
        public int $id,
        public string $login,
        public ?string $name,
        public ?string $avatarUrl,
        public array $verifiedEmails,
        public bool $emailsReadable,
    ) {}

    /** The name to show for a new account. */
    public function displayName(): string
    {
        return trim((string) $this->name) !== '' ? trim((string) $this->name) : $this->login;
    }
}
