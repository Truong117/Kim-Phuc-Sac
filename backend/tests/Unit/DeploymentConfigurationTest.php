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
            if (basename($deploymentFile) === 'Invoke-KpsStagingDatabaseBootstrap.ps1') {
                continue;
            }

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

    public function test_staging_database_audit_is_read_only_and_keeps_secrets_outside_repository(): void
    {
        $audit = $this->readBackendFile('scripts/audit-staging-database.php');
        $wrapper = $this->readRepositoryFile('deployment/Invoke-KpsStagingDatabaseAudit.ps1');

        $this->assertStringContainsString(
            "const EXPECTED_LEGACY_BRANCH = 'kps-weekly-planning-staging';",
            $audit,
        );
        $this->assertStringContainsString("const EXPECTED_INDEPENDENT_INSTANCE = 'KPS-Staging';", $audit);
        $this->assertStringContainsString("const EXPECTED_INDEPENDENT_AUDIT_DATABASE = 'sys';", $audit);
        $this->assertStringContainsString("const EXPECTED_APPLICATION_DATABASE = 'kps_internal';", $audit);
        $this->assertStringContainsString('KPS_STAGING_EXPECTED_FINGERPRINT', $audit);
        $this->assertStringContainsString('KPS_STAGING_EXPECTED_ENDPOINT_FINGERPRINT', $audit);
        $this->assertStringContainsString('KPS_STAGING_CONFIRMED_USERNAME_PREFIX', $audit);
        $this->assertStringContainsString('KPS_PRODUCTION_CONFIRMED_USERNAME_PREFIX', $audit);
        $this->assertStringContainsString('KPS_PRODUCTION_USERNAME_PREFIX_FINGERPRINT', $audit);
        $this->assertStringContainsString('KPS_PRODUCTION_ENDPOINT_FINGERPRINT', $audit);
        $this->assertStringContainsString('KPS_PRODUCTION_CONNECTION_FINGERPRINT', $audit);
        $this->assertStringContainsString('PDO::MYSQL_ATTR_SSL_CA', $audit);
        $this->assertStringContainsString('INFORMATION_SCHEMA.TABLES', $audit);
        $this->assertStringContainsString('INFORMATION_SCHEMA.SCHEMATA', $audit);
        $this->assertStringContainsString('SELECT COUNT(*)', $audit);
        $this->assertStringContainsString('TidbStagingSchemaAssessment::assess(', $audit);
        $this->assertStringContainsString("'user_schema_names'", $audit);
        $this->assertStringContainsString("'allowed_empty_default_schema_names'", $audit);
        $this->assertStringContainsString("'unexpected_user_schema_names'", $audit);
        $this->assertStringContainsString("'user_schema_object_count'", $audit);
        $this->assertStringContainsString('connect_dialog_metadata_tls_and_authenticated_identity', $audit);
        $this->assertStringNotContainsString(
            'connect_dialog_metadata_tls_authenticated_identity_and_empty_schema',
            $audit,
        );
        $this->assertStringContainsString('SHOW GRANTS FOR CURRENT_USER', $audit);
        $this->assertStringContainsString('audit_account_lacks_instance_visibility', $audit);
        $this->assertStringContainsString('server_hostname_verified', $audit);
        $this->assertStringNotContainsString('bootstrap/app.php', $audit);
        $this->assertStringNotContainsString('Dotenv', $audit);
        $this->assertStringNotContainsString('->exec(', $audit);

        foreach ([
            'INSERT INTO',
            'UPDATE `',
            'DELETE FROM',
            'CREATE TABLE',
            'DROP TABLE',
            'ALTER TABLE',
            'TRUNCATE TABLE',
            'REPLACE INTO',
        ] as $writeStatement) {
            $this->assertStringNotContainsString($writeStatement, $audit);
        }

        $this->assertStringContainsString('$env:LOCALAPPDATA', $wrapper);
        $this->assertStringContainsString("[ValidateSet('LegacyBranch', 'IndependentInstance')]", $wrapper);
        $this->assertStringContainsString("ConfigFileName = 'staging-db-audit.json'", $wrapper);
        $this->assertStringContainsString("ConfigFileName = 'independent-staging-db-audit.json'", $wrapper);
        $this->assertStringContainsString('Configuration already exists and was not overwritten', $wrapper);
        $this->assertStringContainsString('version = 3', $wrapper);
        $this->assertStringContainsString('Get-StarterUsernamePrefix', $wrapper);
        $this->assertStringContainsString('The staging and production username prefixes match', $wrapper);
        $this->assertStringNotContainsString(
            'The staging endpoint matches KPS-Internal production',
            $wrapper,
        );
        $this->assertStringContainsString('ConvertFrom-SecureString', $wrapper);
        $this->assertStringContainsString('ConvertTo-SecureString', $wrapper);
        $this->assertStringContainsString('outside the Git repository', $wrapper);
        $this->assertStringNotContainsString('tidbcloud.com', $audit.$wrapper);
    }

    public function test_staging_bootstrap_is_manual_fail_closed_and_separates_runtime_credentials(): void
    {
        $wrapper = $this->readRepositoryFile('deployment/Invoke-KpsStagingDatabaseBootstrap.ps1');
        $helper = $this->readBackendFile('scripts/bootstrap-staging-database.php');
        $stagingEnvironment = $this->readBackendFile('.env.staging.example');

        $this->assertStringContainsString("[ValidateSet('Plan', 'Execute', 'Status')]", $wrapper);
        $this->assertStringContainsString("[string]\$Mode = 'Plan'", $wrapper);
        $this->assertStringContainsString('Get-VerifiedEmptyAudit', $wrapper);
        $this->assertStringContainsString('ApprovalFingerprint', $wrapper);
        $this->assertStringContainsString("Get-CommonDatabaseEnvironment \$Config \$Password 'plan'", $wrapper);
        $this->assertStringNotContainsString('KPS_STAGING_SERVER_HOSTNAME_FINGERPRINT', $wrapper.$helper);
        $this->assertStringContainsString('Read-Host', $wrapper);
        $this->assertStringContainsString('BOOTSTRAP KPS-STAGING', $wrapper);
        $this->assertStringContainsString('ConvertFrom-SecureString', $wrapper);
        $this->assertStringContainsString('ConvertTo-SecureString', $wrapper);
        $this->assertStringContainsString('independent-staging-bootstrap-secrets.json', $wrapper);
        $this->assertStringContainsString("@('migrate', '--force', '--no-interaction')", $wrapper);
        $this->assertStringContainsString('--class=Database\\Seeders\\StagingSeeder', $wrapper);
        $this->assertStringNotContainsString('backend/.env', $wrapper);
        $this->assertStringNotContainsString('Set-Content -LiteralPath', str_replace(
            'Set-Content -LiteralPath $ResolvedSecretsPath',
            '',
            $wrapper,
        ));

        $this->assertStringContainsString("const APPLICATION_DATABASE = 'kps_internal';", $this->readBackendFile(
            'app/Support/StagingBootstrapPlan.php',
        ));
        $this->assertStringContainsString("'writes_executed' => false", $helper);
        $this->assertStringContainsString("'empty_database_required' => false", $helper);
        $this->assertStringContainsString('CREATE DATABASE {$database}', $helper);
        $this->assertStringContainsString('CREATE USER {$migrationAccount}', $helper);
        $this->assertStringContainsString('CREATE USER {$runtimeAccount}', $helper);
        $this->assertStringContainsString('KPS_STAGING_BOOTSTRAP_CONFIRMATION', $helper);
        $this->assertStringContainsString('KPS_STAGING_TARGET_TYPE', $helper);
        $this->assertStringContainsString('KPS_STAGING_METADATA_SOURCE', $helper);
        $this->assertStringContainsString('KPS_PRODUCTION_USERNAME_PREFIX_FINGERPRINT', $helper);
        $this->assertStringContainsString('unverified_target_metadata', $helper);
        $this->assertStringContainsString('initial_empty_state_changed', $helper);
        $this->assertStringContainsString('provisioning_failed_with_possible_partial_ddl', $helper);
        $this->assertStringContainsString('StagingBootstrapGuard::approvalFingerprintMatches', $helper);
        $this->assertStringContainsString('CURRENT_USER() AS authenticated_user', $helper);
        $this->assertStringContainsString("\$identity['authenticated_user']", $helper);
        $this->assertStringNotContainsString('CURRENT_USER() AS current_user', $helper);
        $this->assertStringContainsString('identity_preflight_query_failed', $helper);
        $this->assertStringContainsString('provisioning_preflight_query_failed', $helper);
        $this->assertStringContainsString("'phase' => 'preflight'", $helper);
        $this->assertStringContainsString("'writes_executed' => false", $helper);

        $identityQueryPosition = strpos($helper, 'CURRENT_USER() AS authenticated_user');
        $firstWritePosition = strpos($helper, 'CREATE DATABASE {$database}');

        $this->assertIsInt($identityQueryPosition);
        $this->assertIsInt($firstWritePosition);
        $this->assertLessThan($firstWritePosition, $identityQueryPosition);
        $this->assertStringContainsString('Execution stopped before any later bootstrap stage.', $wrapper);
        $this->assertStringNotContainsString('DROP DATABASE', $helper);
        $this->assertStringNotContainsString('DROP USER', $helper);
        $this->assertStringContainsString('DB_DATABASE=kps_internal', $stagingEnvironment);
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
