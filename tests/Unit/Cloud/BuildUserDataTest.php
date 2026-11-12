<?php

declare(strict_types=1);

namespace Tests\Unit\Cloud;

use App\Domain\Cloud\Actions\BuildUserData;
use App\Domain\Cloud\Models\HostTemplate;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class BuildUserDataTest extends TestCase
{
    private const TOKEN = 'jhe_abc123.DEF-456';

    private const HOST = 'cloud-host-1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.url' => 'https://ci.example.com',
            'jogger.cloud.agent_image' => 'ghcr.io/example/jogger-agent:1.2.3',
            'jogger.default_runner_image' => 'ghcr.io/example/jogger-runner:4.5.6',
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function parsed(array $options = [], string $token = self::TOKEN, string $host = self::HOST): array
    {
        $document = (new BuildUserData)(new HostTemplate(['options' => $options]), $token, $host);

        return Yaml::parse($document);
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function file(array $document, string $path): array
    {
        foreach ($document['write_files'] as $file) {
            if ($file['path'] === $path) {
                return $file;
            }
        }

        $this->fail("No write_files entry for {$path}.");
    }

    public function test_document_is_cloud_config_with_write_files_and_runcmd(): void
    {
        $document = (new BuildUserData)(new HostTemplate, self::TOKEN, self::HOST);

        $this->assertStringStartsWith("#cloud-config\n", $document);

        $parsed = Yaml::parse($document);
        $this->assertSame(['write_files', 'runcmd'], array_keys($parsed));
        $this->assertSame(['/etc/jogger/agent.env', '/usr/local/sbin/jogger-bootstrap'], array_column($parsed['write_files'], 'path'));
    }

    public function test_env_file_is_root_only_and_holds_server_token_and_host(): void
    {
        $file = $this->file($this->parsed(), '/etc/jogger/agent.env');

        $this->assertSame('0600', $file['permissions']);
        $this->assertSame('root:root', $file['owner']);
        $this->assertSame(
            "JOGGER_SERVER=https://ci.example.com\nJOGGER_ENROLL_TOKEN=".self::TOKEN."\nJOGGER_HOSTNAME=".self::HOST."\n",
            $file['content'],
        );
    }

    public function test_dind_image_is_written_only_when_configured(): void
    {
        $without = $this->file($this->parsed(), '/etc/jogger/agent.env')['content'];
        $this->assertStringNotContainsString('JOGGER_DIND_IMAGE', $without);

        $with = $this->file($this->parsed(['dind_image' => 'docker:27-dind-rootless']), '/etc/jogger/agent.env')['content'];
        $this->assertStringContainsString("JOGGER_DIND_IMAGE=docker:27-dind-rootless\n", $with);
    }

    public function test_secrets_stay_out_of_runcmd_and_the_script(): void
    {
        $parsed = $this->parsed();

        $this->assertSame(['/usr/local/sbin/jogger-bootstrap'], $parsed['runcmd']);

        $script = $this->file($parsed, '/usr/local/sbin/jogger-bootstrap')['content'];
        $this->assertStringNotContainsString(self::TOKEN, $script);
        $this->assertStringNotContainsString(self::HOST, $script);
        $this->assertStringNotContainsString('ci.example.com', $script);
    }

    public function test_bootstrap_is_executable_and_blocks_metadata_idempotently(): void
    {
        $file = $this->file($this->parsed(), '/usr/local/sbin/jogger-bootstrap');
        $script = $file['content'];

        $this->assertSame('0755', $file['permissions']);
        $this->assertStringStartsWith("#!/usr/bin/env bash\nset -euo pipefail\n", $script);
        $this->assertStringContainsString('iptables -C DOCKER-USER -d 169.254.169.254 -j DROP', $script);
        $this->assertStringContainsString('iptables -I DOCKER-USER -d 169.254.169.254 -j DROP', $script);
    }

    public function test_bootstrap_runs_the_agent_like_the_documentation(): void
    {
        $script = $this->file($this->parsed(), '/usr/local/sbin/jogger-bootstrap')['content'];

        $this->assertStringContainsString('AGENT_IMAGE=\'ghcr.io/example/jogger-agent:1.2.3\'', $script);
        $this->assertStringContainsString('RUNNER_IMAGE=\'ghcr.io/example/jogger-runner:4.5.6\'', $script);
        $this->assertStringContainsString('command -v docker', $script);
        $this->assertStringContainsString('https://get.docker.com | sh', $script);
        $this->assertStringContainsString('--restart unless-stopped', $script);
        $this->assertStringContainsString('-v /var/run/docker.sock:/var/run/docker.sock', $script);
        $this->assertStringContainsString('-v jogger-agent-data:/var/lib/jogger-agent', $script);
        $this->assertStringContainsString('--env-file "$ENV_FILE"', $script);
        $this->assertStringContainsString('--group-add "$(stat -c %g "$SOCKET")"', $script);
        $this->assertStringEndsWith("rm -f \"\$ENV_FILE\"\n", $script);
    }

    public function test_bootstrap_has_valid_bash_syntax(): void
    {
        $bash = (new ExecutableFinder)->find('bash');
        if ($bash === null) {
            $this->markTestSkipped('bash is not available.');
        }

        $script = $this->file($this->parsed(['dind_image' => 'docker:27-dind-rootless']), '/usr/local/sbin/jogger-bootstrap')['content'];

        $process = new Process([$bash, '-n']);
        $process->setInput($script);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeValues(): array
    {
        return [
            'space' => ['jhe abc'],
            'shell metacharacters' => ['jhe;rm -rf /'],
            'command substitution' => ['$(id)'],
            'quote' => ["jhe'abc"],
            'trailing newline' => ["jhe_abc\n"],
            'empty' => [''],
        ];
    }

    #[DataProvider('unsafeValues')]
    public function test_rejects_unsafe_token(string $token): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->parsed(token: $token);
    }

    #[DataProvider('unsafeValues')]
    public function test_rejects_unsafe_host_name(string $host): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->parsed(host: $host);
    }

    public function test_rejects_server_url_with_whitespace(): void
    {
        config(['app.url' => "https://ci.example.com\nJOGGER_ENROLL_TOKEN=evil"]);

        $this->expectException(InvalidArgumentException::class);

        $this->parsed();
    }
}
