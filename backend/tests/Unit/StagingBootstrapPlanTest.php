<?php

namespace Tests\Unit;

use App\Support\StagingBootstrapPlan;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StagingBootstrapPlanTest extends TestCase
{
    public function test_reviewed_manifest_contains_the_current_seventeen_migrations_in_order(): void
    {
        $manifest = StagingBootstrapPlan::migrationManifest(dirname(__DIR__, 2).'/database/migrations');

        $this->assertCount(17, $manifest['ordered']);
        $this->assertSame(StagingBootstrapPlan::EXPECTED_MIGRATIONS, $manifest['ordered']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $manifest['fingerprint']);
    }

    public function test_database_accounts_use_the_confirmed_starter_prefix(): void
    {
        $this->assertSame([
            'migration_operator' => 'StagePrefix123.kps_migrator',
            'runtime' => 'StagePrefix123.kps_runtime',
        ], StagingBootstrapPlan::databaseAccounts('StagePrefix123'));
    }

    public function test_plan_fingerprint_is_deterministic_and_binds_stable_inputs(): void
    {
        $arguments = [
            str_repeat('a', 64),
            'StagePrefix123',
            str_repeat('b', 64),
        ];

        $first = StagingBootstrapPlan::planFingerprint(...$arguments);
        $second = StagingBootstrapPlan::planFingerprint(...$arguments);

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertNotSame(
            $first,
            StagingBootstrapPlan::planFingerprint(
                $arguments[0],
                $arguments[1],
                str_repeat('c', 64),
            ),
        );
    }

    public function test_invalid_prefix_cannot_form_bootstrap_accounts(): void
    {
        $this->expectException(RuntimeException::class);

        StagingBootstrapPlan::databaseAccounts('invalid.prefix');
    }

    public function test_fixture_accounts_use_only_reserved_test_addresses(): void
    {
        foreach (StagingBootstrapPlan::TEST_ACCOUNTS as $account) {
            $this->assertStringEndsWith('.example.test', $account['email']);
            $this->assertStringContainsString('(Fake)', $account['name']);
        }
    }
}
