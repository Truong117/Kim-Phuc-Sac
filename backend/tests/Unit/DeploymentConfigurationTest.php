<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentConfigurationTest extends TestCase
{
    public function test_container_startup_and_composer_hooks_do_not_run_migrations(): void
    {
        $repositoryRoot = dirname(__DIR__, 3);
        $dockerfile = $this->readBackendFile('Dockerfile');

        $forbiddenDatabaseCommand = '/(?:php\s+)?artisan\s+(?:migrate(?::[\w-]+)?|db:(?:seed|wipe))\b/i';
        $executableDockerfile = implode("\n", array_filter(
            preg_split('/\R/', $dockerfile) ?: [],
            static fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
        ));

        $this->assertDoesNotMatchRegularExpression($forbiddenDatabaseCommand, $executableDockerfile);

        $scriptCommands = [];
        foreach ([
            $this->readBackendFile('composer.json'),
            $this->readBackendFile('package.json'),
            $this->readRepositoryFile('package.json'),
        ] as $manifestContents) {
            $manifest = json_decode($manifestContents, true, flags: JSON_THROW_ON_ERROR);
            $scripts = $manifest['scripts'] ?? [];
            array_walk_recursive(
                $scripts,
                static function (mixed $command) use (&$scriptCommands): void {
                    if (is_string($command)) {
                        $scriptCommands[] = $command;
                    }
                },
            );
        }
        $this->assertDoesNotMatchRegularExpression(
            $forbiddenDatabaseCommand,
            implode("\n", $scriptCommands),
        );

        $workflowFiles = array_merge(
            glob($repositoryRoot.'/.github/workflows/*.yml') ?: [],
            glob($repositoryRoot.'/.github/workflows/*.yaml') ?: [],
        );
        $this->assertNotEmpty($workflowFiles);

        foreach ($workflowFiles as $workflowFile) {
            $contents = file_get_contents($workflowFile);
            $this->assertIsString($contents);
            $this->assertDoesNotMatchRegularExpression($forbiddenDatabaseCommand, $contents);
        }

        $deploymentFiles = array_unique(array_merge(
            glob($repositoryRoot.'/render*.yml') ?: [],
            glob($repositoryRoot.'/render*.yaml') ?: [],
            glob($repositoryRoot.'/Procfile*') ?: [],
            glob($repositoryRoot.'/deployment/*.sh') ?: [],
            glob($repositoryRoot.'/deployment/*.ps1') ?: [],
            glob($repositoryRoot.'/backend/*.sh') ?: [],
            glob($repositoryRoot.'/backend/*.ps1') ?: [],
        ));

        foreach ($deploymentFiles as $deploymentFile) {
            $contents = file_get_contents($deploymentFile);
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
        $this->assertStringNotContainsString('environment_safety.enforce', $provider);
        $this->assertStringNotContainsString('KPS_ENVIRONMENT_SAFETY_ENFORCE', $configuration);
        $this->assertStringNotContainsString('KPS_ALLOW_REMOTE_DATABASE', $configuration);
        $this->assertStringContainsString('Local MySQL must use a loopback host.', $this->readBackendFile('app/Support/EnvironmentSafety.php'));
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
