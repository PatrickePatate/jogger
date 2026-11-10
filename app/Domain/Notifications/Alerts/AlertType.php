<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Alerts;

enum AlertType: string
{
    case HostOffline = 'host_offline';
    case PoolCannotProvision = 'pool_cannot_provision';
    case GithubAuthFailure = 'github_auth_failure';
    case DiskPressure = 'disk_pressure';
    case WebhookFailures = 'webhook_failures';

    public function label(): string
    {
        return match ($this) {
            self::HostOffline => 'Host offline',
            self::PoolCannotProvision => 'Pool cannot start runners',
            self::GithubAuthFailure => 'GitHub rejects our credentials',
            self::DiskPressure => 'Host low on disk space',
            self::WebhookFailures => 'Webhook deliveries failing',
        };
    }
}
