<?php

declare(strict_types=1);

namespace App\Domain\Github\Support;

/**
 * GitHub only delivers webhooks to a public HTTPS address. This inspects APP_URL (heuristically:
 * it cannot know about tunnels or firewalls) so the UI can warn before someone connects an app that
 * will never receive an event.
 */
final readonly class WebhookReachability
{
    public function __construct(public string $url, public bool $https, public bool $public) {}

    public static function check(?string $url = null): self
    {
        $url = rtrim((string) ($url ?? config('app.url')), '/');
        $parts = parse_url($url) ?: [];
        $host = trim(strtolower((string) ($parts['host'] ?? '')), '[]'); // IPv6 hosts come bracketed

        return new self($url, ($parts['scheme'] ?? '') === 'https', ! self::isLocalHost($host));
    }

    public function ok(): bool
    {
        return $this->https && $this->public;
    }

    public function warning(): ?string
    {
        return match (true) {
            ! $this->public => "APP_URL ({$this->url}) is a local address, so GitHub cannot deliver webhooks to it. Expose Jogger on a public HTTPS URL (a reverse proxy or a tunnel such as cloudflared) and update APP_URL before connecting.",
            ! $this->https => "APP_URL ({$this->url}) is not HTTPS. GitHub only delivers webhooks to HTTPS addresses, so job events will not arrive until you serve Jogger over HTTPS.",
            default => null,
        };
    }

    private static function isLocalHost(string $host): bool
    {
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')
            || str_ends_with($host, '.test') || str_ends_with($host, '.internal') || ! str_contains($host, '.') && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
        }

        return false;
    }
}
