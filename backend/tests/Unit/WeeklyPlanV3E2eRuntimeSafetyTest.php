<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\WeeklyPlanV3E2eRuntimeSafety;
use Tests\Support\WeeklyPlanV3IsolatedTestEnvironment;

class WeeklyPlanV3E2eRuntimeSafetyTest extends TestCase
{
    public function test_effective_configuration_and_canonical_paths_are_checked_without_database_access(): void
    {
        $root = dirname(__DIR__, 2);
        $config = $this->safeConfig($root);

        $this->assertSame([], WeeklyPlanV3E2eRuntimeSafety::violations($config, $root, false));
        $this->assertContains(
            'Cached Laravel configuration is forbidden for browser E2E.',
            WeeklyPlanV3E2eRuntimeSafety::violations($config, $root, true),
        );

        $config['database']['connections']['sqlite']['url'] = 'mysql://example.test/staging';
        $config['filesystems']['disks']['weekly_plan_e2e']['root'] = $root.'/storage/app/private';
        $config['logging']['default'] = 'single';
        $violations = WeeklyPlanV3E2eRuntimeSafety::violations($config, $root, false);

        $this->assertContains('Effective SQLite connection URL must be absent.', $violations);
        $this->assertContains('Effective Laravel filesystems.disks.weekly_plan_e2e.root escapes the isolated E2E directory.', $violations);
        $this->assertContains('Effective Laravel logging.default differs from the reviewed E2E profile.', $violations);
    }

    /** @return array<string, mixed> */
    private function safeConfig(string $root): array
    {
        return [
            'app' => ['env' => 'local', 'url' => 'http://127.0.0.1:8001'],
            'database' => [
                'default' => 'sqlite',
                'connections' => ['sqlite' => [
                    'driver' => 'sqlite',
                    'database' => WeeklyPlanV3IsolatedTestEnvironment::E2E_DATABASE,
                    'url' => null,
                ]],
            ],
            'weekly_plans' => [
                'workflow_v3_enabled' => true,
                'storage_disk' => WeeklyPlanV3IsolatedTestEnvironment::E2E_STORAGE_DISK,
            ],
            'filesystems' => [
                'default' => WeeklyPlanV3IsolatedTestEnvironment::E2E_STORAGE_DISK,
                'disks' => ['weekly_plan_e2e' => [
                    'driver' => 'local',
                    'root' => $root.'/'.WeeklyPlanV3E2eRuntimeSafety::ROOT.'/files',
                ]],
            ],
            'session' => ['driver' => 'database', 'cookie' => 'kps_v3_e2e_session', 'connection' => null, 'domain' => null],
            'cache' => ['default' => 'database', 'prefix' => 'kps_v3_e2e_', 'stores' => ['database' => ['connection' => null]]],
            'queue' => ['default' => 'sync'],
            'mail' => ['default' => 'array'],
            'logging' => [
                'default' => 'weekly_plan_e2e',
                'channels' => ['weekly_plan_e2e' => [
                    'driver' => 'single',
                    'path' => $root.'/'.WeeklyPlanV3E2eRuntimeSafety::ROOT.'/logs/laravel.log',
                ]],
            ],
        ];
    }
}
