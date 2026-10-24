<?php

namespace Tests\Unit;

use App\Support\StagingBootstrapGuard;
use App\Support\StagingBootstrapPlan;
use PHPUnit\Framework\TestCase;

class StagingBootstrapGuardTest extends TestCase
{
    public function test_plan_fingerprint_is_accepted_unchanged_and_rejected_after_plan_changes(): void
    {
        $connectionFingerprint = str_repeat('a', 64);
        $migrationFingerprint = str_repeat('b', 64);
        $planned = StagingBootstrapPlan::planFingerprint(
            $connectionFingerprint,
            'StagePrefix123',
            $migrationFingerprint,
        );

        $this->assertTrue(StagingBootstrapGuard::approvalFingerprintMatches($planned, $planned));

        $changedPlan = StagingBootstrapPlan::planFingerprint(
            $connectionFingerprint,
            'StagePrefix123',
            str_repeat('c', 64),
        );

        $this->assertFalse(StagingBootstrapGuard::approvalFingerprintMatches($changedPlan, $planned));
        $this->assertFalse(StagingBootstrapGuard::approvalFingerprintMatches($planned, 'invalid'));
    }

    public function test_exact_staging_target_with_explicit_enable_flag_is_allowed(): void
    {
        $this->assertSame([], StagingBootstrapGuard::seedViolations(
            'staging',
            'staging',
            'mysql',
            'kps_internal',
            true,
            false,
        ));
    }

    public function test_production_and_wrong_database_are_rejected(): void
    {
        $violations = StagingBootstrapGuard::seedViolations(
            'production',
            'production',
            'mysql',
            'kps_internal_production',
            true,
            false,
        );

        $this->assertNotEmpty($violations);
        $this->assertStringContainsString('APP_ENV=staging', implode(' ', $violations));
        $this->assertStringContainsString('kps_internal', implode(' ', $violations));
    }

    public function test_missing_one_off_enable_flag_is_rejected(): void
    {
        $violations = StagingBootstrapGuard::seedViolations(
            'staging',
            'staging',
            'mysql',
            'kps_internal',
            false,
            false,
        );

        $this->assertContains(
            'Staging fixture seeding requires the one-off bootstrap enable flag.',
            $violations,
        );
    }

    public function test_only_sqlite_memory_is_allowed_for_automated_tests(): void
    {
        $this->assertSame([], StagingBootstrapGuard::seedViolations(
            'testing',
            'testing',
            'sqlite',
            ':memory:',
            false,
            true,
        ));

        $this->assertNotEmpty(StagingBootstrapGuard::seedViolations(
            'testing',
            'testing',
            'sqlite',
            'database.sqlite',
            false,
            true,
        ));
    }
}
