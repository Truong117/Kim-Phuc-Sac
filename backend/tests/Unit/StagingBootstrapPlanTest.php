<?php

namespace Tests\Unit;

use App\Support\StagingBootstrapPlan;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class StagingBootstrapPlanTest extends TestCase
{
    public function test_reviewed_manifest_contains_the_current_thirty_three_migrations_in_order(): void
    {
        $manifest = StagingBootstrapPlan::migrationManifest(dirname(__DIR__, 2).'/database/migrations');

        $this->assertCount(37, $manifest['ordered']);
        $this->assertSame('2026_11_10_020000_create_report_automation_tables', $manifest['ordered'][36]);
        $this->assertSame('2026_11_10_000000_create_facebook_review_plans', $manifest['ordered'][34]);
        $this->assertSame('2026_11_10_010000_create_facebook_monthly_reports', $manifest['ordered'][35]);
        $this->assertSame('2026_11_09_010000_create_facebook_age_milestones', $manifest['ordered'][33]);
        $this->assertSame('2026_11_09_000000_add_facebook_sampling_schedule', $manifest['ordered'][32]);
        $this->assertSame('2026_11_07_000000_create_facebook_analytics_tables', $manifest['ordered'][31]);
        $this->assertSame('2026_11_06_010000_create_mail_foundation_tables', $manifest['ordered'][30]);
        $this->assertSame('2026_11_06_000000_add_facebook_selections_to_report_items', $manifest['ordered'][29]);
        $this->assertSame('2026_11_05_000000_create_integration_entries_table', $manifest['ordered'][27]);
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
