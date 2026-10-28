<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StagingIncrementalMigrationLauncherTest extends TestCase
{
    public function test_launcher_bootstraps_and_constructs_the_allowlisted_command_without_handling_it(): void
    {
        $backendRoot = dirname(__DIR__, 2);
        $launcherPath = $backendRoot.'/scripts/run-staging-incremental-migration.php';
        $environmentPath = sys_get_temp_dir().'/kps-runner-autoload-'.bin2hex(random_bytes(8));

        if (! mkdir($environmentPath, 0700)) {
            throw new RuntimeException('Unable to create the isolated launcher test directory.');
        }

        file_put_contents(
            $environmentPath.'/.env',
            'KPS_RUNNER_TEST_POISON=loaded-from-dotenv'.PHP_EOL,
        );

        try {
            $result = $this->runIsolatedLauncherHarness(
                backendRoot: $backendRoot,
                launcherPath: $launcherPath,
                environmentPath: $environmentPath,
            );
        } finally {
            @unlink($environmentPath.'/.env');
            @rmdir($environmentPath);
        }

        $this->assertSame('', $result['stderr']);
        $this->assertSame(0, $result['exit_code']);

        /** @var array<string, mixed> $payload */
        $payload = json_decode($result['stdout'], true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($payload['isolated_environment_verified']);
        $this->assertTrue($payload['application_class_available']);
        $this->assertTrue($payload['application_bootstrapped']);
        $this->assertSame(1, $payload['composer_autoload_include_count']);
        $this->assertFalse($payload['configuration_cached']);
        $this->assertFalse($payload['dotenv_value_loaded']);
        $this->assertTrue($payload['connection_verified']);
        $this->assertSame(2, $payload['connection_query_count']);
        $this->assertTrue($payload['console_kernel_resolved']);
        $this->assertSame('migrate', $payload['input']['command']);
        $this->assertSame('mysql', $payload['input']['database']);
        $this->assertSame(
            'database/migrations/2026_10_27_000000_add_google_docs_source_to_weekly_plans.php',
            $payload['input']['path'],
        );
        $this->assertTrue($payload['input']['force']);
        $this->assertTrue($payload['input']['no_interaction']);
        $this->assertFalse($payload['input']['interactive']);
        $this->assertSame(0, $payload['kernel_handle_call_count']);
    }

    /**
     * @return array{stdout: string, stderr: string, exit_code: int}
     */
    private function runIsolatedLauncherHarness(
        string $backendRoot,
        string $launcherPath,
        string $environmentPath,
    ): array {
        $code = sprintf(
            <<<'PHP'
                $launcherPath = %s;
                $environmentPath = %s;
                require $launcherPath;

                runnerAssertIsolatedEnvironment();
                $application = runnerBootstrapApplication($environmentPath);

                $connection = new class
                {
                    /** @var list<string> */
                    public array $queries = [];

                    public function selectOne(string $query): object
                    {
                        $this->queries[] = $query;

                        if (str_starts_with($query, 'SELECT DATABASE()')) {
                            return (object) [
                                'database_name' => 'kps_internal',
                                'authenticated_user' => 'TestPrefix.kps_migrator@%%',
                                'version_value' => '8.0.11-TiDB-v8.5.3-serverless',
                                'tidb_version_value' => 'Release Version: v8.5.3-serverless',
                                'foreign_key_checks' => 1,
                            ];
                        }

                        if ($query === "SHOW STATUS LIKE 'Ssl_cipher'") {
                            return (object) ['Value' => 'TLS_TEST_CIPHER'];
                        }

                        throw new RuntimeException('Unexpected fake connection query.');
                    }
                };
                $databaseManager = new class($connection)
                {
                    public function __construct(private readonly object $connection) {}

                    public function connection(string $name): object
                    {
                        if ($name !== 'mysql') {
                            throw new RuntimeException('Unexpected fake connection name.');
                        }

                        return $this->connection;
                    }
                };
                $application->instance('db', $databaseManager);

                runnerVerifyMigrationConnection($application);
                $kernel = $application->make(Illuminate\Contracts\Console\Kernel::class);
                $input = runnerMigrationInput(
                    '2026_10_27_000000_add_google_docs_source_to_weekly_plans.php',
                );
                $autoloadPath = realpath(dirname($launcherPath, 2).'/vendor/autoload.php');
                $autoloadIncludes = array_filter(
                    get_included_files(),
                    static fn (string $file): bool => realpath($file) === $autoloadPath,
                );

                echo json_encode([
                    'isolated_environment_verified' => true,
                    'application_class_available' => class_exists(Illuminate\Foundation\Application::class),
                    'application_bootstrapped' => $application->hasBeenBootstrapped(),
                    'composer_autoload_include_count' => count($autoloadIncludes),
                    'configuration_cached' => $application->configurationIsCached(),
                    'dotenv_value_loaded' => getenv('KPS_RUNNER_TEST_POISON') !== false
                        || array_key_exists('KPS_RUNNER_TEST_POISON', $_ENV)
                        || array_key_exists('KPS_RUNNER_TEST_POISON', $_SERVER),
                    'connection_verified' => true,
                    'connection_query_count' => count($connection->queries),
                    'console_kernel_resolved' => $kernel instanceof Illuminate\Contracts\Console\Kernel,
                    'input' => [
                        'command' => $input->getFirstArgument(),
                        'database' => $input->getParameterOption('--database'),
                        'path' => $input->getParameterOption('--path'),
                        'force' => $input->hasParameterOption('--force'),
                        'no_interaction' => $input->hasParameterOption('--no-interaction'),
                        'interactive' => $input->isInteractive(),
                    ],
                    'kernel_handle_call_count' => 0,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                PHP,
            var_export($launcherPath, true),
            var_export($environmentPath, true),
        );
        $environment = [
            'SystemRoot' => (string) (getenv('SystemRoot') ?: sys_get_temp_dir()),
            'WINDIR' => (string) (getenv('WINDIR') ?: sys_get_temp_dir()),
            'TEMP' => sys_get_temp_dir(),
            'TMP' => sys_get_temp_dir(),
            'APP_ENV' => 'staging',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'KPS_DATABASE_ENVIRONMENT' => 'staging',
            'KPS_RUNNER_DB_HOST' => 'local.invalid',
            'KPS_RUNNER_DB_PORT' => '4000',
            'KPS_RUNNER_DB_DATABASE' => 'kps_internal',
            'KPS_RUNNER_DB_USERNAME' => 'TestPrefix.kps_migrator',
            'KPS_RUNNER_DB_PASSWORD' => 'test-only-password',
            'KPS_RUNNER_DB_SSL_CA' => $launcherPath,
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'FILESYSTEM_DISK' => 'local',
            'WEEKLY_PLAN_STORAGE_DISK' => 'local',
            'KPS_STAGING_EPHEMERAL_FILES' => 'true',
            'KPS_AI_DRIVER' => 'fake',
            'WEEKLY_PLAN_DOCUMENT_AI_DRIVER' => 'none',
            'LOG_CHANNEL' => 'stderr',
            'LOG_LEVEL' => 'warning',
        ];
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            $descriptors,
            $pipes,
            $backendRoot,
            $environment,
            ['bypass_shell' => true],
        );

        if (! is_resource($process)) {
            throw new RuntimeException('Unable to start the isolated launcher test process.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_code' => $exitCode,
        ];
    }
}
