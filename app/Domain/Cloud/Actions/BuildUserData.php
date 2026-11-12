<?php

declare(strict_types=1);

namespace App\Domain\Cloud\Actions;

use App\Domain\Cloud\Models\HostTemplate;
use InvalidArgumentException;

/**
 * Cloud-init user data for a new server. It writes the agent's environment file and a bootstrap script, and runs the
 * script, which installs Docker when it is missing and starts the agent the way docs/AGENT.md describes.
 *
 * symfony/yaml is a dev-only dependency, so the document is written by hand. The token and host name are limited to
 * a character set that needs no quoting; the server URL and the optional DIND image may not contain whitespace. The
 * script and the env file go in literal blocks, and the image names reach the script shell-quoted.
 */
final class BuildUserData
{
    private const SAFE_PATTERN = '/^[A-Za-z0-9_.-]+$/D';

    private const ENV_FILE = '/etc/jogger/agent.env';

    private const BOOTSTRAP = '/usr/local/sbin/jogger-bootstrap';

    public function __invoke(HostTemplate $template, string $enrollToken, string $hostName): string
    {
        $this->assertSafe('enrollment token', $enrollToken);
        $this->assertSafe('host name', $hostName);

        $server = (string) config('app.url');
        $this->assertOneLine('app.url', $server);

        $env = [
            'JOGGER_SERVER='.$server,
            'JOGGER_ENROLL_TOKEN='.$enrollToken,
            'JOGGER_HOSTNAME='.$hostName,
        ];

        $dind = $template->options['dind_image'] ?? null;
        if (is_string($dind) && $dind !== '') {
            $this->assertOneLine('dind_image', $dind);
            $env[] = 'JOGGER_DIND_IMAGE='.$dind;
        }

        $script = $this->bootstrap(
            (string) config('jogger.cloud.agent_image'),
            (string) config('jogger.default_runner_image'),
        );

        $lines = [
            '#cloud-config',
            'write_files:',
            ...$this->writeFile(self::ENV_FILE, '0600', implode("\n", $env)."\n"),
            ...$this->writeFile(self::BOOTSTRAP, '0755', $script),
            'runcmd:',
            '  - '.self::BOOTSTRAP,
        ];

        return implode("\n", $lines)."\n";
    }

    private function bootstrap(string $agentImage, string $runnerImage): string
    {
        return implode("\n", [
            '#!/usr/bin/env bash',
            'set -euo pipefail',
            '',
            'ENV_FILE='.escapeshellarg(self::ENV_FILE),
            'AGENT_IMAGE='.escapeshellarg($agentImage),
            'RUNNER_IMAGE='.escapeshellarg($runnerImage),
            'SOCKET=/var/run/docker.sock',
            '',
            '# A snapshot image already has Docker; a stock image gets it here.',
            'if ! command -v docker >/dev/null 2>&1; then',
            '    curl -fsSL https://get.docker.com | sh',
            'fi',
            '',
            '# Containers must not reach the cloud metadata service, which exposes this server\'s provider credentials.',
            'if ! iptables -C DOCKER-USER -d 169.254.169.254 -j DROP 2>/dev/null; then',
            '    iptables -I DOCKER-USER -d 169.254.169.254 -j DROP',
            'fi',
            '',
            'for image in "$AGENT_IMAGE" "$RUNNER_IMAGE"; do',
            '    if ! docker image inspect "$image" >/dev/null 2>&1; then',
            '        docker pull "$image"',
            '    fi',
            'done',
            '',
            'if ! docker container inspect jogger-agent >/dev/null 2>&1; then',
            '    docker run -d --name jogger-agent --restart unless-stopped \\',
            '        -v /var/run/docker.sock:/var/run/docker.sock \\',
            '        -v jogger-agent-data:/var/lib/jogger-agent \\',
            '        --env-file "$ENV_FILE" \\',
            '        --group-add "$(stat -c %g "$SOCKET")" \\',
            '        "$AGENT_IMAGE"',
            'fi',
            '',
            '# The enrollment token is single-use, and the agent keeps its credential in the data volume.',
            'rm -f "$ENV_FILE"',
            '',
        ]);
    }

    /**
     * @return list<string>
     */
    private function writeFile(string $path, string $mode, string $content): array
    {
        $lines = [
            '  - path: '.$path,
            "    permissions: '{$mode}'",
            "    owner: 'root:root'",
            '    content: |',
        ];

        foreach (explode("\n", rtrim($content, "\n")) as $line) {
            $lines[] = $line === '' ? '' : '      '.$line;
        }

        return $lines;
    }

    private function assertSafe(string $what, string $value): void
    {
        if (preg_match(self::SAFE_PATTERN, $value) !== 1) {
            throw new InvalidArgumentException("The {$what} may only contain letters, digits, '_', '.' and '-'.");
        }
    }

    private function assertOneLine(string $what, string $value): void
    {
        if ($value === '' || preg_match('/\s/', $value) === 1) {
            throw new InvalidArgumentException("The {$what} must be a non-empty value without whitespace.");
        }
    }
}
