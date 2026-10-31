<?php

namespace Tests\Unit;

use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\WeeklyPlanningV3E2eFixture;
use Tests\Support\WeeklyPlanV3IsolatedTestEnvironment;

class WeeklyPlanV3IsolatedTestEnvironmentTest extends TestCase
{
    public function test_exact_browser_e2e_profile_is_accepted(): void
    {
        $this->assertSame([], WeeklyPlanV3IsolatedTestEnvironment::e2eViolations(
            $this->safeE2eEnvironment(),
        ));
    }

    #[DataProvider('unsafeE2eEnvironmentProvider')]
    public function test_browser_e2e_profile_fails_closed(array $changes, string $violation): void
    {
        $violations = WeeklyPlanV3IsolatedTestEnvironment::e2eViolations(array_replace(
            $this->safeE2eEnvironment(),
            $changes,
        ));

        $this->assertContains($violation, $violations);
    }

    public static function unsafeE2eEnvironmentProvider(): array
    {
        return [
            'missing gate' => [
                ['KPS_V3_E2E_TEST_ENABLED' => 'false'],
                'KPS_V3_E2E_TEST_ENABLED must be explicitly true.',
            ],
            'staging app' => [
                ['APP_ENV' => 'staging'],
                'External test infrastructure may run only with APP_ENV=local.',
            ],
            'unverified database' => [
                ['DB_DATABASE' => 'database/database.sqlite'],
                'Browser E2E must use the exact isolated SQLite path.',
            ],
            'path traversal database' => [
                ['DB_DATABASE' => '../../storage/framework/testing/weekly-plan-v3-e2e/database/weekly-plan-v3-e2e.sqlite'],
                'Browser E2E must use the exact isolated SQLite path.',
            ],
            'remote frontend' => [
                ['FRONTEND_URL' => 'https://staging.example.test'],
                'FRONTEND_URL must use an HTTP loopback origin.',
            ],
            'credentialed backend origin' => [
                ['APP_URL' => 'http://user:password@127.0.0.1:8001'],
                'APP_URL must use an HTTP loopback origin.',
            ],
            'remote Sanctum domain' => [
                ['SANCTUM_STATEFUL_DOMAINS' => '127.0.0.1:5174,staging.example.test'],
                'SANCTUM_STATEFUL_DOMAINS must contain only loopback authorities.',
            ],
            'hosted marker' => [
                ['CI' => 'true'],
                'CI must be absent from the local external test process.',
            ],
            'shared session files' => [
                ['SESSION_DRIVER' => 'file'],
                'External test infrastructure must keep sessions in the isolated database.',
            ],
        ];
    }

    public function test_exact_disposable_tidb_profile_and_version_are_accepted(): void
    {
        $this->assertSame([], WeeklyPlanV3IsolatedTestEnvironment::tidbViolations(
            $this->safeTidbEnvironment(),
            '5.7.25-TiDB-v8.5.8',
        ));
    }

    #[DataProvider('unsafeTidbEnvironmentProvider')]
    public function test_tidb_profile_fails_closed(array $changes, ?string $version, string $violation): void
    {
        $violations = WeeklyPlanV3IsolatedTestEnvironment::tidbViolations(
            array_replace($this->safeTidbEnvironment(), $changes),
            $version,
        );

        $this->assertContains($violation, $violations);
    }

    public static function unsafeTidbEnvironmentProvider(): array
    {
        return [
            'remote host' => [
                ['DB_HOST' => 'gateway.example.test'],
                '5.7.25-TiDB-v8.5.8',
                'TiDB tests must use a loopback host.',
            ],
            'unsafe database name' => [
                ['DB_DATABASE' => 'kps_internal'],
                '5.7.25-TiDB-v8.5.8',
                'TiDB tests must use a kps_v3_phase7b1_* throwaway database.',
            ],
            'wrong account' => [
                ['DB_USERNAME' => 'root'],
                '5.7.25-TiDB-v8.5.8',
                'TiDB tests must use the dedicated synthetic account.',
            ],
            'not TiDB' => [
                [],
                '8.4.0 MySQL Community Server',
                'The server must identify itself as the pinned TiDB version.',
            ],
            'wrong TiDB release' => [
                [],
                '5.7.25-TiDB-v8.5.7',
                'The server must identify itself as the pinned TiDB version.',
            ],
        ];
    }

    public function test_guard_throws_with_all_violations(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Unsafe Weekly Planning V3 external test environment');

        WeeklyPlanV3IsolatedTestEnvironment::assertSafe(['first', 'second']);
    }

    public function test_synthetic_fixture_has_deterministic_non_company_identities(): void
    {
        $emails = array_column(WeeklyPlanningV3E2eFixture::ACCOUNTS, 'email');
        $employeeEmails = array_column(WeeklyPlanningV3E2eFixture::EMPLOYEES, 'email');

        $this->assertCount(count(array_unique($emails)), $emails);
        $this->assertCount(count(array_unique($employeeEmails)), $employeeEmails);
        $this->assertNotSame('', WeeklyPlanningV3E2eFixture::PASSWORD);

        foreach ([...$emails, ...$employeeEmails] as $email) {
            $this->assertStringEndsWith('.example.test', $email);
        }
    }

    public function test_reviewed_profile_examples_match_the_guard_contract(): void
    {
        $backendRoot = dirname(__DIR__, 2);
        $e2eProfile = file_get_contents($backendRoot.'/.env.weekly-planning-v3-e2e.example');
        $tidbProfile = file_get_contents($backendRoot.'/.env.weekly-planning-v3-tidb.example');

        $this->assertIsString($e2eProfile);
        $this->assertIsString($tidbProfile);
        $this->assertStringContainsString(
            'DB_DATABASE='.WeeklyPlanV3IsolatedTestEnvironment::E2E_DATABASE,
            $e2eProfile,
        );
        $this->assertStringContainsString(
            'WEEKLY_PLAN_STORAGE_DISK='.WeeklyPlanV3IsolatedTestEnvironment::E2E_STORAGE_DISK,
            $e2eProfile,
        );
        $this->assertStringContainsString(
            'KPS_V3_TIDB_PINNED_VERSION='.WeeklyPlanV3IsolatedTestEnvironment::TIDB_PINNED_VERSION,
            $tidbProfile,
        );
        $this->assertStringNotContainsString('kps-staging', strtolower($e2eProfile.$tidbProfile));
        $this->assertStringNotContainsString('kps-internal', strtolower($e2eProfile.$tidbProfile));
    }

    /** @return array<string, string> */
    private function safeE2eEnvironment(): array
    {
        return [
            'KPS_V3_E2E_TEST_ENABLED' => 'true',
            'APP_ENV' => 'local',
            'KPS_DATABASE_ENVIRONMENT' => 'development',
            'WEEKLY_PLAN_WORKFLOW_V3_ENABLED' => 'true',
            'DB_CONNECTION' => 'sqlite',
            'DB_URL' => '',
            'DB_DATABASE' => WeeklyPlanV3IsolatedTestEnvironment::E2E_DATABASE,
            'WEEKLY_PLAN_STORAGE_DISK' => WeeklyPlanV3IsolatedTestEnvironment::E2E_STORAGE_DISK,
            'FILESYSTEM_DISK' => WeeklyPlanV3IsolatedTestEnvironment::E2E_STORAGE_DISK,
            'APP_URL' => 'http://127.0.0.1:8001',
            'FRONTEND_URL' => 'http://127.0.0.1:5174',
            'SANCTUM_STATEFUL_DOMAINS' => '127.0.0.1:5174',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'database',
            'CACHE_STORE' => 'database',
        ];
    }

    /** @return array<string, string> */
    private function safeTidbEnvironment(): array
    {
        return [
            'KPS_V3_TIDB_TEST_ENABLED' => 'true',
            'APP_ENV' => 'local',
            'KPS_DATABASE_ENVIRONMENT' => 'development',
            'WEEKLY_PLAN_WORKFLOW_V3_ENABLED' => 'true',
            'DB_CONNECTION' => 'mysql',
            'DB_URL' => '',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '4000',
            'DB_DATABASE' => 'kps_v3_phase7b1_local',
            'DB_USERNAME' => 'kps_v3_phase7b1',
            'DB_PASSWORD' => 'synthetic-local-only',
            'KPS_V3_TIDB_PINNED_VERSION' => WeeklyPlanV3IsolatedTestEnvironment::TIDB_PINNED_VERSION,
            'FILESYSTEM_DISK' => WeeklyPlanV3IsolatedTestEnvironment::E2E_STORAGE_DISK,
            'SESSION_DRIVER' => 'database',
            'CACHE_STORE' => 'database',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ];
    }
}
