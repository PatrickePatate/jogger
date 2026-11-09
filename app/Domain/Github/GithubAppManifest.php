<?php

declare(strict_types=1);

namespace App\Domain\Github;

use Illuminate\Support\Str;

/**
 * The GitHub App manifest Jogger registers with. Permission names, events and URLs follow GitHub's
 * "Registering a GitHub App from a manifest" docs (see docs/DECISIONS.md, Task 14).
 */
final class GithubAppManifest
{
    /** GitHub limits app names to 34 characters. */
    public const MAX_NAME_LENGTH = 34;

    /**
     * @return array<string, mixed>
     */
    public static function build(?string $name = null, ?string $appUrl = null): array
    {
        $appUrl = rtrim((string) ($appUrl ?? config('app.url')), '/');

        return [
            'name' => self::name($name, $appUrl),
            'url' => $appUrl,
            'description' => 'Runs your GitHub Actions jobs on your own hardware, in ephemeral Docker runners.',
            'public' => false,
            'hook_attributes' => ['url' => $appUrl.'/webhooks/github', 'active' => true],
            // Where GitHub sends the browser (with a one-time code) after the app is created.
            'redirect_url' => $appUrl.'/github/manifest/callback',
            // Sign in with GitHub (Task 17).
            'callback_urls' => [$appUrl.'/auth/github/callback'],
            // After installing (or changing an installation) GitHub returns the admin here.
            'setup_url' => $appUrl.'/github',
            'setup_on_update' => true,
            'request_oauth_on_install' => false,
            // `installation` and `installation_repositories` are delivered to every GitHub App automatically
            // and cannot be subscribed to, so only these two are requested.
            'default_events' => ['workflow_job', 'repository'],
            'default_permissions' => [
                'actions' => 'read',                          // workflow runs/jobs and the workflow_job webhook
                'administration' => 'write',                  // repository-level runners (JIT config, delete)
                'metadata' => 'read',                         // mandatory; also needed for the repository event
                'organization_self_hosted_runners' => 'write', // organization-level runners
            ],
        ];
    }

    /** Defaults to "Jogger" plus the host, so names stay unique across installations. */
    private static function name(?string $name, string $appUrl): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            $host = (string) (parse_url($appUrl, PHP_URL_HOST) ?: 'local');
            $name = 'Jogger '.Str::before($host, '.');
        }

        return Str::limit($name, self::MAX_NAME_LENGTH, '');
    }
}
