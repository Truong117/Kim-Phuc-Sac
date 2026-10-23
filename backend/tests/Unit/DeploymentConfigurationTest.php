<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentConfigurationTest extends TestCase
{
    public function test_container_startup_and_composer_hooks_do_not_run_migrations(): void
    {
        $dockerfile = $this->readBackendFile('Dockerfile');
        $composer = json_decode(
            $this->readBackendFile('composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $forbiddenDatabaseCommand = '/(?:php\s+)?artisan\s+(?:migrate(?::[\w-]+)?|db:(?:seed|wipe))\b/i';
        $executableDockerfile = implode("\n", array_filter(
            preg_split('/\R/', $dockerfile) ?: [],
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
        ));

        $this->assertDoesNotMatchRegularExpression($forbiddenDatabaseCommand, $executableDockerfile);

        $composerCommands = [];
        $composerScripts = $composer['scripts'] ?? [];
        array_walk_recursive(
            $composerScripts,
            static function (mixed $command) use (&$composerCommands): void {
                if (is_string($command)) {
                    $composerCommands[] = $command;
                }
            },
        );
        $this->assertDoesNotMatchRegularExpression(
            $forbiddenDatabaseCommand,
            implode("\n", $composerCommands),
        );

        $workflowFiles = array_merge(
            glob(dirname(__DIR__, 3).'/.github/workflows/*.yml') ?: [],
            glob(dirname(__DIR__, 3).'/.github/workflows/*.yaml') ?: [],
        );
        $this->assertNotEmpty($workflowFiles);

        foreach ($workflowFiles as $workflowFile) {
            $contents = file_get_contents($workflowFile);
            $this->assertIsString($contents);
            $this->assertDoesNotMatchRegularExpression($forbiddenDatabaseCommand, $contents);
        }
    }

    public function test_runtime_environment_safety_has_no_general_opt_out(): void
    {
        $provider = $this->readBackendFile('app/Providers/AppServiceProvider.php');
        $configuration = $this->readBackendFile('config/environment_safety.php');

        $this->assertStringContainsString('EnvironmentSafety::violations(', $provider);
        $this->assertStringContainsString('EnvironmentSafety::assertNoViolations(', $provider);
        $this->assertStringContainsString('EnvironmentSafety::shouldSkipConsoleBootstrap(', $provider);
        $this->assertStringContainsString("\$consoleCommand === 'test'", $provider);
        $this->assertStringContainsString("'database.connections.sqlite.database' => ':memory:'", $provider);
        $this->assertStringContainsString("allow_remote_database', false) === true", $provider);
        $this->assertStringNotContainsString('environment_safety.enforce', $provider);
        $this->assertStringNotContainsString('KPS_ENVIRONMENT_SAFETY_ENFORCE', $configuration);
        $this->assertStringContainsString("env('KPS_ALLOW_REMOTE_DATABASE', false) === true", $configuration);
    }

    public function test_docker_build_context_excludes_local_secrets_and_private_files(): void
    {
        $dockerignore = $this->readBackendFile('.dockerignore');

        $this->assertStringContainsString(".env\n", str_replace("\r\n", "\n", $dockerignore));
        $this->assertStringContainsString('storage/app/private/*', $dockerignore);
        $this->assertStringContainsString('storage/framework/*', $dockerignore);
        $this->assertStringContainsString('bootstrap/cache/*', $dockerignore);
        $this->assertStringContainsString('public/storage', $dockerignore);
        $this->assertStringContainsString('.vscode', $dockerignore);
        $this->assertStringContainsString('public/build', $dockerignore);
        $this->assertStringContainsString('.phpunit.cache', $dockerignore);
        $this->assertStringContainsString('database/*.sqlite', $dockerignore);
    }

    public function test_docker_image_declares_s3_platform_requirements(): void
    {
        $dockerfile = $this->readBackendFile('Dockerfile');

        $this->assertStringContainsString('simplexml', $dockerfile);
        $this->assertStringContainsString('curl', $dockerfile);
        $this->assertStringContainsString('composer check-platform-reqs --no-dev', $dockerfile);
    }

    public function test_git_ignores_real_environment_files_but_keeps_templates(): void
    {
        foreach ([
            $this->readRepositoryFile('.gitignore'),
            $this->readBackendFile('.gitignore'),
        ] as $gitignore) {
            $this->assertStringContainsString(".env.*\n", str_replace("\r\n", "\n", $gitignore));
            $this->assertStringContainsString('!.env.*.example', $gitignore);
        }
    }

    public function test_vercel_proxy_is_environment_specific_and_fails_closed(): void
    {
        $entrypoint = $this->readRepositoryFile('vercel.mjs');
        $factory = $this->readRepositoryFile('deployment/vercel-config.mjs');

        $this->assertStringContainsString('process.env.KPS_BACKEND_ORIGIN', $entrypoint);
        $this->assertStringContainsString('/api/:path*', $factory);
        $this->assertStringContainsString('/sanctum/:path*', $factory);
        $this->assertStringContainsString('/index.html', $factory);
        $this->assertStringContainsString('x-vercel-enable-rewrite-caching', $factory);
        $this->assertStringContainsString('must be configured', $factory);
        $this->assertStringNotContainsString(
            'onrender.com',
            $entrypoint.$factory,
        );
        $this->assertFileDoesNotExist(dirname(__DIR__, 3).'/vercel.json');
    }

    private function readBackendFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2).DIRECTORY_SEPARATOR.$path);

        $this->assertIsString($contents);

        return $contents;
    }

    private function readRepositoryFile(string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3).DIRECTORY_SEPARATOR.$path);

        $this->assertIsString($contents);

        return $contents;
    }
}
