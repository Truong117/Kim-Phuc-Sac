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
            glob($repositoryRoot.'/backend/scripts/*.ps1') ?: [],
        ));

        foreach ($deploymentFiles as $deploymentFile) {
            if (in_array(basename($deploymentFile), [
                'Invoke-KpsStagingDatabaseBootstrap.ps1',
                'Invoke-KpsStagingIncrementalMigration.ps1',
            ], true)) {
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

    public function test_render_container_uses_concurrent_frankenphp_with_public_only_web_root(): void
    {
        $dockerfile = $this->readBackendFile('Dockerfile');
        $caddyfile = $this->readBackendFile('Caddyfile');
        $phpConfiguration = $this->readBackendFile('docker/frankenphp.ini');
        $smokeTest = $this->readBackendFile('scripts/Test-ProductionContainer.ps1');
        $slowFixture = $this->readBackendFile('tests/Deployment/slow-request.php');
        $workflow = $this->readRepositoryFile('.github/workflows/ci.yml');
        $bootstrap = $this->readBackendFile('bootstrap/app.php');
        $routes = $this->readBackendFile('routes/api.php');

        $this->assertStringContainsString('FROM dunglas/frankenphp:1-php8.2-bookworm', $dockerfile);
        $this->assertStringNotContainsString('artisan serve', $dockerfile);
        $this->assertStringNotContainsString('PHP_CLI_SERVER_WORKERS', $dockerfile);
        $this->assertStringNotContainsString('octane', strtolower($dockerfile.$caddyfile));
        foreach (['pdo_mysql', 'curl', 'simplexml', 'mbstring', 'bcmath', 'pcntl', 'zip'] as $extension) {
            $this->assertStringContainsString($extension, $dockerfile);
        }
        $this->assertStringContainsString('composer install', $dockerfile);
        $this->assertStringContainsString('php artisan config:cache', $dockerfile);
        $this->assertStringContainsString('exec frankenphp run', $dockerfile);
        $this->assertStringContainsString('USER www-data', $dockerfile);
        $this->assertStringContainsString('EXPOSE 10000', $dockerfile);

        $this->assertStringContainsString('http://0.0.0.0:{$PORT:10000}', $caddyfile);
        $this->assertStringContainsString('root * /app/public', $caddyfile);
        $this->assertStringContainsString('num_threads {$FRANKENPHP_NUM_THREADS:4}', $caddyfile);
        $this->assertStringContainsString('output stdout', $caddyfile);
        $this->assertStringContainsString('replace token REDACTED', $caddyfile);
        $this->assertStringContainsString('php_server', $caddyfile);
        $this->assertStringContainsString('/.env*', $caddyfile);
        $this->assertStringContainsString('/storage/*', $caddyfile);
        $this->assertStringContainsString('/vendor/*', $caddyfile);

        $this->assertStringContainsString('max_execution_time = 420', $phpConfiguration);
        $this->assertStringContainsString('error_log = /proc/self/fd/2', $phpConfiguration);
        $this->assertStringContainsString('opcache.enable = 1', $phpConfiguration);

        $this->assertStringContainsString('Start-Job', $smokeTest);
        $this->assertStringContainsString('CONCURRENT_UP_STATUS=', $smokeTest);
        $this->assertStringContainsString('FORWARDED_HEADERS_STATUS=PASS', $smokeTest);
        $this->assertStringContainsString('/api/health', $smokeTest);
        $this->assertStringContainsString('/sanctum/csrf-cookie', $smokeTest);
        $this->assertStringContainsString("'X-Forwarded-Proto' = 'https'", $smokeTest);
        $this->assertStringContainsString("'KPS_AI_DRIVER=fake'", $smokeTest);
        $this->assertStringContainsString("'WEEKLY_PLAN_DOCUMENT_AI_DRIVER=none'", $smokeTest);
        $this->assertStringContainsString('sleep(6)', $slowFixture);
        $this->assertStringContainsString('Test-ProductionContainer.ps1 -Image kps-backend-ci -SkipBuild', $workflow);

        $this->assertStringContainsString("health: '/up'", $bootstrap);
        $this->assertStringContainsString("Route::get('/health'", $routes);
        $this->assertStringContainsString("Route::post('/{id}/source/ai-extract'", $routes);
        $this->assertStringContainsString("Route::post('/google-docs/preview'", $routes);
        $this->assertStringNotContainsString('__kps_concurrency_smoke', $routes);
    }

    public function test_ephemeral_weekly_plan_storage_is_explicitly_limited_to_staging(): void
    {
        $staging = $this->readBackendFile('.env.staging.example');
        $production = $this->readBackendFile('.env.production.example');
        $guard = $this->readBackendFile('app/Support/EnvironmentSafety.php');

        $this->assertStringContainsString('FILESYSTEM_DISK=local', $staging);
        $this->assertStringContainsString('WEEKLY_PLAN_STORAGE_DISK=local', $staging);
        $this->assertStringContainsString('KPS_STAGING_EPHEMERAL_FILES=true', $staging);
        $this->assertStringContainsString('FILESYSTEM_DISK=s3', $production);
        $this->assertStringContainsString('WEEKLY_PLAN_STORAGE_DISK=s3', $production);
        $this->assertStringContainsString('KPS_STAGING_EPHEMERAL_FILES=false', $production);
        $this->assertStringContainsString("\$appEnvironment === 'production'", $guard);
        $this->assertStringContainsString("\$appEnvironment === 'staging' && ! \$stagingEphemeralFiles", $guard);
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

    public function test_incremental_staging_runner_is_allowlisted_manual_and_never_seeds(): void
    {
        $wrapper = $this->readRepositoryFile('deployment/Invoke-KpsStagingIncrementalMigration.ps1');
        $helper = $this->readBackendFile('scripts/inspect-staging-incremental-migration.php');
        $launcher = $this->readBackendFile('scripts/run-staging-incremental-migration.php');
        $databaseConfiguration = $this->readBackendFile('config/database.php');

        $this->assertStringContainsString('2026_10_27_000000_add_google_docs_source_to_weekly_plans.php', $wrapper);
        $this->assertStringContainsString('2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables.php', $wrapper);
        $this->assertStringContainsString('[switch]$Status', $wrapper);
        $this->assertStringContainsString('[switch]$Plan', $wrapper);
        $this->assertStringContainsString('[switch]$Execute', $wrapper);
        $this->assertStringContainsString('PlanReceiptPath', $wrapper);
        $this->assertStringContainsString('Windows DPAPI CurrentUser', $wrapper);
        $this->assertStringContainsString('AcknowledgeBackupAvailable', $wrapper);
        $this->assertStringContainsString('AcknowledgeRecoveryPoint', $wrapper);
        $this->assertStringContainsString('AcknowledgeNewInstanceRestore', $wrapper);
        $this->assertStringContainsString('AcknowledgeRpo', $wrapper);
        $this->assertStringContainsString('MIGRATE KPS-STAGING', $wrapper);
        $this->assertStringContainsString('Local\KPS-Staging-Incremental-Migration', $wrapper);
        $this->assertStringContainsString('EnvironmentVariables.Clear()', $wrapper);
        $this->assertStringContainsString('Open-VerifiedMigrationFile', $wrapper);
        $this->assertStringContainsString("'command' => 'migrate'", $launcher);
        $this->assertStringContainsString("'--database' => 'mysql'", $launcher);
        $this->assertStringContainsString("'--path' => 'database/migrations/'", $launcher);
        $this->assertStringContainsString("'--force' => true", $launcher);
        $this->assertStringContainsString("'--no-interaction' => true", $launcher);
        $this->assertStringNotContainsString('db:seed', $wrapper.$helper.$launcher);
        $this->assertStringNotContainsString('StagingSeeder', $wrapper.$helper.$launcher);
        $this->assertStringNotContainsString('Invoke-KpsStagingDatabaseBootstrap', $wrapper);
        $this->assertStringNotContainsString('backend/.env', $wrapper.$helper.$launcher);
        $this->assertStringNotContainsString('LoadEnvironmentVariables::class', $launcher);
        $this->assertStringContainsString("'url' => null", $launcher);
        $this->assertStringContainsString("'unix_socket' => ''", $launcher);
        $this->assertStringContainsString('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true', $helper);
        $this->assertStringContainsString(
            'Mysql::ATTR_SSL_VERIFY_SERVER_CERT : PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT) => true',
            $launcher,
        );
        $this->assertStringNotContainsString('ATTR_SSL_VERIFY_SERVER_CERT', $databaseConfiguration);
        $this->assertStringContainsString('CURRENT_USER() AS authenticated_user', $helper);
        $this->assertStringContainsString('CURRENT_USER() AS authenticated_user', $launcher);
        $this->assertStringContainsString('@@foreign_key_checks AS foreign_key_checks', $helper);
        $this->assertStringContainsString('@@foreign_key_checks AS foreign_key_checks', $launcher);
        $this->assertStringContainsString('ADMIN SHOW DDL JOBS 100', $helper);
        $this->assertStringContainsString('INFORMATION_SCHEMA.PROCESSLIST', $helper);
        $this->assertStringNotContainsString('->exec(', $helper);

        $readOnlyExitPosition = strpos($wrapper, "if (\$Mode -ne 'Execute') {");
        $artisanPosition = strpos($wrapper, 'Invoke-ArtisanMigration $artisanEnvironment $Migration');
        $acknowledgementPosition = strpos($wrapper, "if (\$Mode -eq 'Execute') {");
        $configReadPosition = strpos($wrapper, '$config = Get-ValidatedAuditConfig');
        $confirmationPosition = strpos($wrapper, '$confirmation = Read-RunnerConfirmation');
        $finalPreflightPosition = strpos($wrapper, '$finalPreflight = Invoke-Inspector');
        $finalHashPosition = strpos($wrapper, '$finalMigrationHash = Get-FileSha256');

        $this->assertIsInt($readOnlyExitPosition);
        $this->assertIsInt($artisanPosition);
        $this->assertIsInt($acknowledgementPosition);
        $this->assertIsInt($configReadPosition);
        $this->assertIsInt($confirmationPosition);
        $this->assertIsInt($finalPreflightPosition);
        $this->assertIsInt($finalHashPosition);
        $this->assertLessThan($artisanPosition, $readOnlyExitPosition);
        $this->assertLessThan($configReadPosition, $acknowledgementPosition);
        $this->assertLessThan($finalPreflightPosition, $confirmationPosition);
        $this->assertLessThan($finalHashPosition, $finalPreflightPosition);
        $this->assertLessThan($artisanPosition, $finalHashPosition);

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
            $this->assertStringNotContainsString($writeStatement, $helper);
        }
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
