<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ProductionEphemeralStorageBootstrapTest extends TestCase
{
    /** @return iterable<string, array{?string, string, bool}> */
    public static function profiles(): iterable
    {
        yield 'explicit production opt-in' => ['true', 'false', true];
        yield 'missing opt-in' => [null, 'false', false];
        yield 'disabled opt-in' => ['false', 'false', false];
        yield 'truthy string is not consent' => ['yes', 'false', false];
        yield 'staging flag cannot bypass production' => [null, 'true', false];
        yield 'both flags are rejected' => ['true', 'true', false];
    }

    #[DataProvider('profiles')]
    public function test_real_production_configuration_bootstrap_without_database_io(
        ?string $productionOptIn,
        string $stagingOptIn,
        bool $allowed,
    ): void {
        // A separate process exercises env parsing, configuration and the real
        // provider guard. No .env, real credential or database connection is used.
        $script = <<<'PHP'
require 'vendor/autoload.php';
$_SERVER['argv'] = ['production-storage-preflight'];
try {
    $app = require 'bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.production-storage-fixture-does-not-exist');
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if (Illuminate\Support\Facades\DB::getConnections() !== []) {
        throw new RuntimeException('Unexpected database connection.');
    }
    echo json_encode([
        'environment' => config('app.env'),
        'debug' => config('app.debug'),
        'database_environment' => config('environment_safety.database_environment'),
        'weekly_disk' => config('weekly_plans.storage_disk'),
        'production_opt_in' => config('environment_safety.production_ephemeral_weekly_files'),
        'default_disk' => config('filesystems.default'),
        'session_driver' => config('session.driver'),
    ], JSON_THROW_ON_ERROR);
} catch (LogicException $exception) {
    echo $exception->getMessage();
    exit(78);
}
PHP;

        $process = new Process([PHP_BINARY, '-r', $script], dirname(__DIR__, 2), [
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_CONFIG_CACHE' => 'bootstrap/cache/ephemeral-configuration-test-not-cached.php',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('0', 32)),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => 'database.example.invalid',
            'DB_DATABASE' => 'configuration_fixture_only',
            'DB_USERNAME' => 'unused_fixture',
            'DB_PASSWORD' => '',
            'DB_URL' => '',
            'KPS_DATABASE_ENVIRONMENT' => 'production',
            'WEEKLY_PLAN_STORAGE_DISK' => 'local',
            'KPS_PRODUCTION_EPHEMERAL_WEEKLY_FILES' => $productionOptIn ?? false,
            'KPS_STAGING_EPHEMERAL_FILES' => $stagingOptIn,
            'FILESYSTEM_DISK' => 's3',
            'SESSION_DRIVER' => 'database',
        ]);
        $process->setTimeout(30);
        $process->run();

        $this->assertSame($allowed ? 0 : 78, $process->getExitCode(), $process->getOutput().$process->getErrorOutput());
        if (! $allowed) {
            $this->assertStringContainsString('Unsafe KPS environment configuration', $process->getOutput());

            return;
        }

        $this->assertSame([
            'environment' => 'production',
            'debug' => false,
            'database_environment' => 'production',
            'weekly_disk' => 'local',
            'production_opt_in' => true,
            'default_disk' => 's3',
            'session_driver' => 'database',
        ], json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR));
    }
}
