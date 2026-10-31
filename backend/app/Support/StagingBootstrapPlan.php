<?php

namespace App\Support;

use RuntimeException;

final class StagingBootstrapPlan
{
    public const APPROVAL_FINGERPRINT_VERSION = 'v2';

    public const APPLICATION_DATABASE = 'kps_internal';

    /** @var list<string> */
    public const EXPECTED_MIGRATIONS = [
        '0001_01_01_000000_create_users_table',
        '0001_01_01_000001_create_cache_table',
        '0001_01_01_000002_create_jobs_table',
        '2026_10_08_000000_create_authorization_tables',
        '2026_10_09_000000_create_daily_reports_table',
        '2026_10_09_000001_create_daily_report_items_table',
        '2026_10_09_000002_create_report_comments_table',
        '2026_10_09_000003_create_user_notifications_table',
        '2026_10_21_000000_create_ai_daily_report_runs_table',
        '2026_10_22_000000_create_tasks_table',
        '2026_10_22_010000_create_weekly_planning_tables',
        '2026_10_22_010001_add_weekly_plan_source_to_tasks_table',
        '2026_10_22_020000_create_weekly_plan_document_extraction_tables',
        '2026_10_24_000000_create_ai_gateway_usage_tables',
        '2026_10_26_000000_create_organization_google_docs_folder_tables',
        '2026_10_27_000000_add_google_docs_source_to_weekly_plans',
        '2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables',
        '2026_10_28_000000_create_weekly_plan_entry_items_tables',
        '2026_10_29_000000_create_weekly_plan_staged_review_tables',
        '2026_10_30_000000_create_weekly_plan_v3_schema_foundation',
        '2026_10_30_010000_create_weekly_plan_v3_correction_batches',
        '2026_10_31_000001_create_account_email_changes_table',
    ];

    /** @var list<string> */
    public const SEEDERS = [
        'Database\\Seeders\\AuthorizationSeeder',
        'Database\\Seeders\\OrganizationStructureSeeder',
        'Database\\Seeders\\TaskManagementPermissionSeeder',
        'Database\\Seeders\\AiExecutiveDashboardPermissionSeeder',
        'Database\\Seeders\\WeeklyPlanningPermissionSeeder',
        'Database\\Seeders\\StagingTestAccountSeeder',
    ];

    /**
     * These addresses use the reserved .test TLD and do not represent KPS staff.
     *
     * @var list<array{name: string, email: string, role: string, department: string|null}>
     */
    public const TEST_ACCOUNTS = [
        [
            'name' => 'KPS Staging Owner (Fake)',
            'email' => 'owner@kps-staging.example.test',
            'role' => 'OWNER',
            'department' => null,
        ],
        [
            'name' => 'KPS Staging Admin (Fake)',
            'email' => 'admin@kps-staging.example.test',
            'role' => 'ADMIN',
            'department' => null,
        ],
        [
            'name' => 'KPS Staging Executive Assistant (Fake)',
            'email' => 'assistant@kps-staging.example.test',
            'role' => 'EXECUTIVE_ASSISTANT',
            'department' => 'HR_ADMIN',
        ],
        [
            'name' => 'KPS Staging HR (Fake)',
            'email' => 'hr@kps-staging.example.test',
            'role' => 'HR_MANAGER',
            'department' => 'HR_ADMIN',
        ],
        [
            'name' => 'KPS Staging Department Manager (Fake)',
            'email' => 'manager@kps-staging.example.test',
            'role' => 'DEPARTMENT_MANAGER',
            'department' => 'MARKETING',
        ],
        [
            'name' => 'KPS Staging Employee (Fake)',
            'email' => 'employee@kps-staging.example.test',
            'role' => 'OFFICE_STAFF',
            'department' => 'MARKETING',
        ],
    ];

    /** @var list<string> */
    public const MIGRATION_PRIVILEGES = [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'CREATE',
        'ALTER',
        'DROP',
        'INDEX',
        'REFERENCES',
    ];

    /** @var list<string> */
    public const RUNTIME_PRIVILEGES = [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
    ];

    /** @var list<string> */
    public const EXPECTED_ROLE_CODES = [
        'OWNER',
        'ADMIN',
        'EXECUTIVE_ASSISTANT',
        'HR_MANAGER',
        'DEPARTMENT_MANAGER',
        'OFFICE_STAFF',
        'SALES_MANAGER',
        'SALES_STAFF',
        'KPS_SPA_MANAGER',
        'KPS_SPA_STAFF',
    ];

    /** @var list<string> */
    public const REQUIRED_EXTENSION_PERMISSION_CODES = [
        'tasks.status.update',
        'ai.dashboard.view',
        'weekly_plans.view',
        'weekly_plans.manage_draft',
        'weekly_plans.submit',
        'weekly_plans.assistant_review',
        'weekly_plans.review',
        'weekly_plans.approve',
        'weekly_plans.publish',
        'weekly_plans.google_docs.configure',
    ];

    /** @return array{migration_operator: string, runtime: string} */
    public static function databaseAccounts(string $stagingPrefix): array
    {
        $stagingPrefix = trim($stagingPrefix);
        $accounts = [
            'migration_operator' => $stagingPrefix.'.kps_migrator',
            'runtime' => $stagingPrefix.'.kps_runtime',
        ];

        foreach ($accounts as $account) {
            if (TidbStarterIdentity::parseUsername($account) === null) {
                throw new RuntimeException('The staging prefix cannot form a valid TiDB Starter username.');
            }
        }

        return $accounts;
    }

    /**
     * @return array{ordered: list<string>, hashes: array<string, string>, fingerprint: string}
     */
    public static function migrationManifest(string $migrationDirectory): array
    {
        $paths = glob(rtrim($migrationDirectory, '/\\').DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($paths, SORT_STRING);
        $ordered = array_map(
            static fn (string $path): string => pathinfo($path, PATHINFO_FILENAME),
            $paths,
        );

        if ($ordered !== self::EXPECTED_MIGRATIONS) {
            throw new RuntimeException(sprintf(
                'The migration set differs from the reviewed %d-file staging baseline.',
                count(self::EXPECTED_MIGRATIONS),
            ));
        }

        $hashes = [];

        foreach ($paths as $path) {
            $hash = hash_file('sha256', $path);

            if (! is_string($hash)) {
                throw new RuntimeException('Unable to hash a migration file.');
            }

            $hashes[pathinfo($path, PATHINFO_FILENAME)] = $hash;
        }

        return [
            'ordered' => $ordered,
            'hashes' => $hashes,
            'fingerprint' => hash('sha256', json_encode($hashes, JSON_THROW_ON_ERROR)),
        ];
    }

    public static function planFingerprint(
        string $connectionFingerprint,
        string $stagingPrefix,
        string $migrationFingerprint,
    ): string {
        return hash('sha256', implode('|', [
            self::APPROVAL_FINGERPRINT_VERSION,
            $connectionFingerprint,
            $stagingPrefix,
            self::APPLICATION_DATABASE,
            $migrationFingerprint,
            implode(',', self::SEEDERS),
            json_encode(self::TEST_ACCOUNTS, JSON_THROW_ON_ERROR),
        ]));
    }
}
