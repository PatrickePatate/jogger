<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/** The documentation is checked against the code where it can drift silently. */
class DocumentationTest extends TestCase
{
    public function test_openapi_lists_exactly_the_implemented_api_routes(): void
    {
        $spec = Yaml::parseFile(base_path('docs/openapi.yaml'));
        $this->assertStringStartsWith('3.1', $spec['openapi']);

        $documented = [];
        foreach ($spec['paths'] as $path => $operations) {
            foreach (array_keys($operations) as $method) {
                if (in_array($method, ['get', 'post', 'patch', 'put', 'delete'], true)) {
                    $documented[] = strtoupper($method).' '.$path;
                    $this->assertNotEmpty($operations[$method]['operationId'] ?? null, "{$method} {$path} has no operationId");
                    $this->assertNotEmpty($operations[$method]['responses'] ?? null, "{$method} {$path} has no responses");
                }
            }
        }

        $implemented = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $implemented[] = $method.' /'.substr($route->uri(), strlen('api/v1/'));
            }
        }

        sort($documented);
        sort($implemented);
        $this->assertSame($implemented, $documented);
    }

    public function test_every_local_reference_in_openapi_resolves(): void
    {
        $raw = (string) file_get_contents(base_path('docs/openapi.yaml'));
        $spec = Yaml::parse($raw);
        preg_match_all('~\$ref: "#/([^"]+)"~', $raw, $matches);
        $this->assertNotEmpty($matches[1]);
        foreach (array_unique($matches[1]) as $pointer) {
            $this->assertNotNull(data_get($spec, str_replace('/', '.', $pointer)), "Unresolved reference #/{$pointer}");
        }
    }

    public function test_every_production_env_variable_is_documented(): void
    {
        $docs = (string) file_get_contents(base_path('docs/CONFIGURATION.md'));
        preg_match_all('/^([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(base_path('.env.production.example')), $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $variable) {
            $this->assertStringContainsString("`{$variable}`", $docs, "{$variable} is not documented in docs/CONFIGURATION.md");
        }
    }

    public function test_documents_linked_from_the_readme_exist(): void
    {
        preg_match_all('~\]\(((?:docs/)?[A-Za-z0-9._/-]+\.(?:md|yaml))\)~', (string) file_get_contents(base_path('README.md')), $matches);
        $this->assertNotEmpty($matches[1]);
        foreach ($matches[1] as $path) {
            $this->assertFileExists(base_path($path));
        }
    }
}
