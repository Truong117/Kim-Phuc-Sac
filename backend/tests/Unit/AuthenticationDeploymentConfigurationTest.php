<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AuthenticationDeploymentConfigurationTest extends TestCase
{
    public function test_deployed_examples_use_the_same_origin_proxy_for_sanctum(): void
    {
        foreach (['staging', 'production'] as $environment) {
            $frontend = $this->parseEnvironmentFile(
                dirname(__DIR__, 3)."/.env.{$environment}.example",
            );
            $backend = $this->parseEnvironmentFile(
                dirname(__DIR__, 2)."/.env.{$environment}.example",
            );

            $this->assertSame('/', $frontend['VITE_API_BASE_URL'] ?? null);
            $this->assertSame($backend['APP_URL'] ?? null, $frontend['KPS_BACKEND_ORIGIN'] ?? null);
            $this->assertSame('true', $backend['SESSION_SECURE_COOKIE'] ?? null);
            $this->assertSame('true', $backend['SESSION_HTTP_ONLY'] ?? null);
            $this->assertSame('lax', $backend['SESSION_SAME_SITE'] ?? null);
            $this->assertSame('false', $backend['SESSION_PARTITIONED_COOKIE'] ?? null);
            $this->assertSame('', $backend['SESSION_DOMAIN'] ?? null);

            $frontendUrl = $backend['FRONTEND_URL'] ?? '';
            $this->assertStringStartsWith('https://', $frontendUrl);
            $this->assertSame(
                parse_url($frontendUrl, PHP_URL_HOST),
                $backend['SANCTUM_STATEFUL_DOMAINS'] ?? null,
            );
        }
    }

    public function test_cors_and_sanctum_use_explicit_environment_owned_origins(): void
    {
        $cors = $this->readBackendFile('config/cors.php');
        $sanctum = $this->readBackendFile('config/sanctum.php');

        $this->assertStringContainsString("env('FRONTEND_URL'", $cors);
        $this->assertStringContainsString("'allowed_origins_patterns' => []", $cors);
        $this->assertStringContainsString("'supports_credentials' => true", $cors);
        $this->assertStringContainsString("env('SANCTUM_STATEFUL_DOMAINS'", $sanctum);
    }

    /** @return array<string, string> */
    private function parseEnvironmentFile(string $path): array
    {
        $contents = file_get_contents($path);
        $this->assertIsString($contents);
        $values = [];

        foreach (preg_split('/\R/', $contents) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $values[trim($key)] = trim(trim($value), "\"'");
        }

        return $values;
    }

    private function readBackendFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$path);
        $this->assertIsString($contents);

        return $contents;
    }
}
