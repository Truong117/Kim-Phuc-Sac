<?php

namespace App\Services\AI;

use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Support\StagingBootstrapPlan;
use App\Support\WorkReportAcceptanceDataset;
use App\Support\WorkReportFixtureManifest;
use App\Support\WorkReportFixtureTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use LogicException;

final class WorkReportAcceptanceFixture
{
    public function __construct(private readonly WorkReportFixtureTarget $target, private readonly WorkReportAcceptanceDataset $dataset) {}

    public function dryRun(string $batch, ?string $end, WorkReportFixtureManifest $store): array
    {
        $identity = $this->target->verify();
        $plan = $this->dataset->build($batch, $end);
        $ledger = DB::table('migrations')->orderBy('id')->pluck('migration')->all();
        if (! in_array(count($ledger), [22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33, 34], true) || $ledger !== array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, count($ledger))) {
            throw new LogicException('FIXTURE_UNREVIEWED_MIGRATION_LEDGER');
        }
        if (count($ledger) >= 25 && (! Schema::hasColumn('daily_reports', 'next_day_plan')
            || ! Schema::hasColumns('daily_report_items', ['issue', 'evidence_links']))) {
            throw new LogicException('FIXTURE_DAILY_REPORT_V3_SCHEMA_DRIFT');
        }
        if (count($ledger) < 25 && (Schema::hasColumn('daily_reports', 'next_day_plan')
            || Schema::hasColumn('daily_report_items', 'issue') || Schema::hasColumn('daily_report_items', 'evidence_links'))) {
            throw new LogicException('FIXTURE_DAILY_REPORT_V3_SCHEMA_DRIFT');
        }
        if (Schema::hasColumn('daily_reports', 'is_makeup') !== (count($ledger) >= 26)) {
            throw new LogicException('FIXTURE_MAKEUP_REPORT_SCHEMA_DRIFT');
        }
        foreach (['work_report_analyses' => 23, 'work_report_analysis_units' => 23, 'work_report_analysis_revisions' => 24, 'work_report_regeneration_requests' => 24, 'daily_report_ai_documents' => 27, 'daily_report_ai_units' => 27, 'integration_entries' => 28, 'social_connections' => 29, 'social_posts' => 29, 'social_post_sources' => 29, 'social_metric_snapshots' => 29, 'mail_connections' => 31, 'mail_oauth_attempts' => 31, 'mail_messages' => 31, 'facebook_analytics_states' => 32, 'facebook_analytics_posts' => 32, 'facebook_analytics_samples' => 32, 'facebook_analytics_analyses' => 32, 'facebook_analytics_milestones' => 34] as $table => $step) {
            if (Schema::hasTable($table) !== (count($ledger) >= $step)) {
                throw new LogicException('FIXTURE_PARTIAL_SCHEMA_REQUIRES_RECOVERY');
            }
        }
        foreach (['facebook_analytics_states' => ['sampling_schedule', 'sampling_next_check_at'], 'facebook_analytics_samples' => ['scheduled_for', 'sampling_late']] as $table => $columns) {
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column) !== (count($ledger) >= 33)) {
                    throw new LogicException('FIXTURE_FACEBOOK_SAMPLING_SCHEMA_DRIFT');
                }
            }
        }
        foreach (['objective', 'distribution', 'classified_by', 'classified_at'] as $column) {
            if (Schema::hasColumn('facebook_analytics_posts', $column) !== (count($ledger) >= 34)) {
                throw new LogicException('FIXTURE_FACEBOOK_MILESTONE_SCHEMA_DRIFT');
            }
        }
        if (Schema::hasColumn('daily_report_items', 'facebook_selections') !== (count($ledger) >= 30)) {
            throw new LogicException('FIXTURE_FACEBOOK_SELECTION_SCHEMA_DRIFT');
        }
        $org = DB::table('organizations')->where('code', 'KPS')->where('is_active', true)->first();
        if ($org === null) {
            throw new LogicException('FIXTURE_ACTIVE_KPS_ORGANIZATION_REQUIRED');
        }
        $roles = DB::table('roles')->whereIn('code', ['OWNER', 'OFFICE_STAFF'])->orderBy('code')->pluck('id', 'code')->all();
        if (count($roles) !== 2) {
            throw new LogicException('FIXTURE_EXISTING_RBAC_REQUIRED');
        }
        foreach (['ai.insights.view', 'reports.view'] as $code) {
            if (! DB::table('role_permissions')->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                ->where('role_id', $roles['OWNER'])->where('permissions.code', $code)->whereIn('data_scope', ['ALL', 'ORGANIZATION'])->exists()) {
                throw new LogicException('FIXTURE_EXISTING_REVIEWER_PERMISSIONS_REQUIRED');
            }
        }
        $files = ['app/Support/WorkReportAcceptanceDataset.php', 'app/Support/WorkReportFixtureTarget.php', 'app/Support/WorkReportFixtureManifest.php',
            'app/Services/AI/WorkReportAcceptanceFixture.php', 'app/Console/Commands/WorkReportFixtureCommand.php', 'app/Services/ReportClock.php',
            'app/Services/AI/WorkReportSnapshot.php', 'app/Services/AI/WorkReportAnalysisService.php', 'app/Services/AI/WorkReportAnalysisWorker.php',
            'app/Services/AI/WorkReportAnalysisOutput.php', 'app/Services/AI/WorkReportAnalysisProvider.php',
            'database/migrations/2026_11_02_000000_create_work_report_analyses.php', 'database/migrations/2026_11_02_010000_create_work_report_regeneration_tables.php',
            'database/migrations/2026_11_03_010000_add_daily_report_v3_fields.php', 'database/migrations/2026_11_03_020000_add_makeup_marker_to_daily_reports.php', 'database/migrations/2026_11_03_030000_create_daily_report_ai_documents.php',
            'database/migrations/2026_11_05_000000_create_integration_entries_table.php', 'database/migrations/2026_11_05_010000_create_social_tracking_tables.php', 'database/migrations/2026_11_07_000000_create_facebook_analytics_tables.php', 'database/migrations/2026_11_09_000000_add_facebook_sampling_schedule.php', 'database/migrations/2026_11_09_010000_create_facebook_age_milestones.php'];
        $hashes = [];
        // Match a Git checkout on Windows and Linux without weakening content binding.
        foreach ($files as $file) {
            $hashes[$file] = hash('sha256', str_replace("\r\n", "\n", file_get_contents(base_path($file))));
        }
        $fingerprint = WorkReportFixtureManifest::hash([$identity, $org->id, $roles, $plan, $hashes]);
        $existing = $store->read();
        if ($existing !== null) {
            if (($existing['fingerprint'] ?? '') !== $fingerprint || ($existing['state'] ?? '') !== 'applied') {
                throw new LogicException('FIXTURE_MANIFEST_DIFFERENT_OR_RETIRED_BATCH');
            }
            $this->verifyRows($existing);
        } elseif (DB::table('users')->whereIn('email', array_column($plan['accounts'], 'email'))->exists()
            || DB::table('departments')->where('organization_id', $org->id)->whereIn('code', array_column($plan['departments'], 'code'))->exists()) {
            throw new LogicException('FIXTURE_NAMESPACE_COLLISION_WITHOUT_OWNERSHIP_MANIFEST');
        }
        if (DB::table('daily_reports')->where('organization_id', $org->id)
            ->whereBetween('report_date', [$plan['period']['start_date'], $plan['period']['end_date']])->where('locked_at', '<=', now())
            ->whereNotIn('id', array_column($existing['owned']['daily_reports'] ?? [], 'id'))->exists()) {
            throw new LogicException('FIXTURE_EXTERNAL_REPORTS_IN_ACCEPTANCE_PERIOD');
        }

        $billingPeriod = now('UTC')->format('Y-m');
        $budget = DB::table('ai_budget_periods')->where('environment', config('app.env'))->where('provider', 'openai')->where('billing_period', $billingPeriod)->first();

        return ['status' => 'PASS', 'mode' => 'dry-run', 'writes_executed' => false, 'identity' => $identity, 'fingerprint' => $fingerprint,
            'budget_context' => ['billing_period' => $billingPeriod, 'environment' => config('app.env'), 'provider' => 'openai',
                'reserved_cost_microusd' => (int) ($budget->reserved_cost_microusd ?? 0), 'spent_cost_microusd' => (int) ($budget->spent_cost_microusd ?? 0), 'ledger_reset' => false],
            'schema_ready' => count($ledger) >= 24, 'pending_migrations' => array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, count($ledger)),
            'organization_id' => (int) $org->id, 'roles' => $roles, 'already_applied' => $existing !== null, 'plan' => $plan, 'code_hashes' => $hashes,
            'expected_inserts' => ['users' => 7, 'departments' => 2, 'organization_memberships' => 7, 'membership_roles' => 7,
                'daily_reports' => count($plan['reports']), 'daily_report_items' => array_sum(array_map(fn ($r) => count($r['items']), $plan['reports']))]];
    }

    public function apply(string $batch, string $end, WorkReportFixtureManifest $store, string $approval, array $credentials): array
    {
        return $store->exclusive(function () use ($batch, $end, $store, $approval, $credentials) {
            return DB::transaction(function () use ($batch, $end, $store, $approval, $credentials) {
                $preview = $this->dryRun($batch, $end, $store);
                DB::table('organizations')->where('id', $preview['organization_id'])->lockForUpdate()->firstOrFail();
                // Recheck after acquiring the database lock; no namespace race or permission/config drift.
                $preview = $this->dryRun($batch, $end, $store);
                $this->approve($preview, $approval);
                if (! $preview['schema_ready']) {
                    throw new LogicException('FIXTURE_APPLY_REQUIRES_M23_M24');
                }
                if ($preview['already_applied']) {
                    return ['status' => 'PASS', 'mode' => 'apply', 'reused' => true, 'fingerprint' => $approval];
                }
                $plan = $preview['plan'];
                foreach ($plan['accounts'] as $account) {
                    $password = $credentials[$account['email']] ?? '';
                    if (! is_string($password) || strlen($password) < 24 || strlen($password) > 72) {
                        throw new LogicException('FIXTURE_PRIVATE_CREDENTIALS_REQUIRED_FOR_EVERY_ACCOUNT');
                    }
                }
                if (count(array_unique(array_intersect_key($credentials, array_flip(array_column($plan['accounts'], 'email'))))) !== count($plan['accounts'])) {
                    throw new LogicException('FIXTURE_ACCOUNT_PASSWORDS_MUST_BE_DISTINCT');
                }
                $owned = [];
                $departments = [];
                foreach ($plan['departments'] as $key => $definition) {
                    $departments[$key] = $this->insert($owned, 'departments', ['organization_id' => $preview['organization_id'], ...$definition, 'is_active' => 1]);
                }
                $users = [];
                foreach ($plan['accounts'] as $key => $account) {
                    $id = $this->insert($owned, 'users', ['name' => $account['name'], 'email' => $account['email'], 'email_verified_at' => null], ['password' => Hash::make($credentials[$account['email']])]);
                    $users[$key] = $id;
                    $membership = $this->insert($owned, 'organization_memberships', ['user_id' => $id, 'organization_id' => $preview['organization_id'],
                        'department_id' => $account['department'] === null ? null : $departments[$account['department']], 'location_id' => null, 'is_default' => 1, 'is_active' => 1]);
                    $role = ['membership_id' => $membership, 'role_id' => $preview['roles'][$account['role']], 'is_primary' => 1];
                    DB::table('membership_roles')->insert([...$role, 'created_at' => now(), 'updated_at' => now()]);
                    $owned['membership_roles'][] = $role;
                }
                foreach ($plan['reports'] as $report) {
                    $id = $this->insert($owned, 'daily_reports', ['organization_id' => $preview['organization_id'], 'user_id' => $users[$report['account']],
                        'department_id' => $departments[$report['department']], 'location_id' => null, 'report_date' => $report['date'], 'locked_at' => $report['locked_at']], [], $report['created_at']);
                    foreach ($report['items'] as $item) {
                        unset($item['case_id']);
                        $this->insert($owned, 'daily_report_items', ['daily_report_id' => $id, ...$item], [], $report['created_at']);
                    }
                }
                $manifest = ['state' => 'applied', 'fingerprint' => $approval, 'identity' => $preview['identity'], 'plan' => $plan,
                    'owned' => $owned, 'users' => $users, 'departments' => $departments, 'organization_id' => $preview['organization_id'], 'created_at' => now()->toIso8601String()];
                // Durable signed ownership must exist before commit. File failure rolls back all DML.
                $store->write($manifest);

                return ['status' => 'PASS', 'mode' => 'apply', 'reused' => false, 'fingerprint' => $approval,
                    'reports' => count($owned['daily_reports']), 'items' => count($owned['daily_report_items']), 'accounts' => $plan['accounts']];
            });
        });
    }

    public function fakeReview(string $batch, string $end, WorkReportFixtureManifest $store, string $approval): array
    {
        $preview = $this->dryRun($batch, $end, $store);
        $this->approve($preview, $approval);
        $manifest = $store->read() ?? throw new LogicException('FIXTURE_NOT_APPLIED');
        $keys = ['work_report_ai.enabled', 'work_report_ai.driver', 'work_report_ai.queue_connection', 'work_report_ai.gateway.api_key'];
        $saved = array_combine($keys, array_map(fn ($k) => config($k), $keys));
        $results = [];
        try {
            // Process-local only; no persistent environment changes, paid keys or HTTP fallback.
            config(['work_report_ai.enabled' => true, 'work_report_ai.driver' => 'fake', 'work_report_ai.queue_connection' => 'sync', 'work_report_ai.gateway.api_key' => null]);
            Http::preventStrayRequests();
            $viewer = User::findOrFail($manifest['users']['reviewer']);
            $service = app(WorkReportAnalysisService::class);
            foreach ($preview['plan']['matrix'] as $case) {
                $department = $case['department'] === 'all' ? null : $manifest['departments'][$case['department']];
                $run = $service->request($viewer, $end, $case['days'], $department);
                $payload = $service->payload($viewer, $run);
                if ($run->status !== 'completed' || $payload['work_counts']['reports'] !== $case['reports'] || $payload['work_counts']['items'] !== $case['items']
                    || $run->units()->where('unit_key', 'like', 'employee:%')->count() !== $case['units'] - 1) {
                    throw new LogicException('FIXTURE_FAKE_MATRIX_MISMATCH');
                }
                foreach ($case['no_source_accounts'] as $key) {
                    $person = collect($payload['employees'])->firstWhere('employee_id', $manifest['users'][$key]);
                    if (($person['status'] ?? '') !== 'no_data') {
                        throw new LogicException('FIXTURE_NO_SOURCE_STATUS_MISMATCH');
                    }
                }
                // The source matrix stays stable for existing signed ownership manifests.
                // Actual provider calls/storage now include independently generated summary batches.
                $results[] = [...$case, 'units' => (int) $run->units()->sum('attempts'),
                    'stored_units' => $run->units()->count(), 'analysis_id' => $run->id,
                    'status' => $run->status, 'is_mock' => $payload['is_mock']];
            }
        } finally {
            config($saved);
        }

        return ['status' => 'PASS', 'mode' => 'fake-review', 'matrix' => $results, 'provider_units' => array_sum(array_column($results, 'units')),
            'paid_http_calls' => 0, 'model_quality_certified' => false];
    }

    private function insert(array &$owned, string $table, array $row, array $private = [], mixed $at = null): int
    {
        $id = DB::table($table)->insertGetId([...$row, ...$private, 'created_at' => $at ?? now(), 'updated_at' => $at ?? now()]);
        $owned[$table][] = ['id' => $id, ...$row];

        return $id;
    }

    public function cleanupPlan(string $batch, string $end, WorkReportFixtureManifest $store): array
    {
        $preview = $this->dryRun($batch, $end, $store);
        $manifest = $store->read() ?? throw new LogicException('FIXTURE_NOT_APPLIED');
        $userIds = array_values($manifest['users']);
        $reportIds = array_column($manifest['owned']['daily_reports'], 'id');
        $departmentIds = array_values($manifest['departments']);
        $runs = [];
        foreach (WorkReportAnalysis::where('organization_id', $manifest['organization_id'])->get() as $run) {
            $sourceIds = array_column($run->snapshot['reports'], 'report_id');
            $referenced = array_intersect($sourceIds, $reportIds) !== []
                || array_intersect(array_column($run->snapshot['employees'], 'employee_id'), $userIds) !== [];
            $ownedRequester = in_array((int) $run->requested_by_user_id, $userIds, true);
            if (! $referenced && ! $ownedRequester) {
                continue;
            }
            if (! $ownedRequester || $sourceIds === [] || array_diff($sourceIds, $reportIds) !== [] || in_array($run->status, ['pending', 'running'], true)) {
                throw new LogicException('FIXTURE_ANALYSIS_MIXED_EXTERNAL_OR_ACTIVE');
            }
            $runs[] = (int) $run->id;
        }
        foreach (DB::table('work_report_analysis_revisions')->where(fn ($q) => $q->whereIn('analysis_id', $runs)->orWhereIn('parent_analysis_id', $runs)->orWhereIn('root_analysis_id', $runs))->get() as $revision) {
            if (array_diff([(int) $revision->analysis_id, (int) $revision->parent_analysis_id, (int) $revision->root_analysis_id], $runs) !== []) {
                throw new LogicException('FIXTURE_EXTERNAL_REVISION_REFERENCE');
            }
        }
        $operations = DB::table('work_report_regeneration_requests')->where(fn ($q) => $q->whereIn('source_analysis_id', $runs)->orWhereIn('result_analysis_id', $runs)->orWhereIn('requested_by_user_id', $userIds))->get();
        foreach ($operations as $operation) {
            if (! in_array((int) $operation->requested_by_user_id, $userIds, true)
                || array_diff([(int) $operation->source_analysis_id, (int) $operation->result_analysis_id], $runs) !== []) {
                throw new LogicException('FIXTURE_EXTERNAL_OPERATION_REFERENCE');
            }
        }
        $owned = $manifest['owned'];
        $owned['work_report_analyses'] = array_map(fn ($id) => ['id' => $id], $runs);
        $owned['work_report_analysis_units'] = DB::table('work_report_analysis_units')->whereIn('work_report_analysis_id', $runs)->get(['id'])->map(fn ($r) => (array) $r)->all();
        $owned['work_report_analysis_revisions'] = DB::table('work_report_analysis_revisions')->whereIn('analysis_id', $runs)->get(['analysis_id'])->map(fn ($r) => (array) $r)->all();
        $owned['work_report_regeneration_requests'] = $operations->map(fn ($r) => ['id' => (int) $r->id])->all();
        $parents = ['users' => $userIds, 'departments' => $departmentIds, 'organization_memberships' => array_column($owned['organization_memberships'], 'id'),
            'daily_reports' => $reportIds, 'daily_report_items' => array_column($owned['daily_report_items'], 'id'), 'work_report_analyses' => $runs];
        // Inspect real FKs so unknown/future dependent data is refused rather than cascaded away.
        foreach (Schema::getTableListing() as $qualifiedTable) {
            $table = basename(str_replace('.', '/', $qualifiedTable));
            foreach (Schema::getForeignKeys($table) as $fk) {
                $parent = $fk['foreign_table'];
                if (! isset($parents[$parent]) || $parents[$parent] === []) {
                    continue;
                }
                if (count($fk['columns']) !== 1 || $fk['foreign_columns'] !== ['id']) {
                    throw new LogicException('FIXTURE_UNREVIEWED_COMPOSITE_DEPENDENCY');
                }
                $children = DB::table($table)->whereIn($fk['columns'][0], $parents[$parent])->get();
                if ($table === 'ai_usage_records' && $parent === 'users') {
                    continue;
                } // Never erase billing ledger.
                foreach ($children as $child) {
                    $matches = false;
                    foreach ($owned[$table] ?? [] as $expected) {
                        $key = isset($expected['id']) ? ['id' => $expected['id']] : (isset($expected['analysis_id']) ? ['analysis_id' => $expected['analysis_id']] : $expected);
                        if (collect($key)->every(fn ($value, $column) => (string) ($child->$column ?? '') === (string) ($value ?? ''))) {
                            $matches = true;
                            break;
                        }
                    }
                    if (! $matches) {
                        throw new LogicException('FIXTURE_UNOWNED_FOREIGN_KEY_DEPENDENCY');
                    }
                }
            }
        }
        $retained = DB::table('ai_usage_records')->whereIn('requested_by_user_id', $userIds)->distinct()->pluck('requested_by_user_id')->map(fn ($id) => (int) $id)->all();

        return ['status' => 'PASS', 'mode' => 'cleanup-plan', 'fingerprint' => $preview['fingerprint'], 'analysis_ids' => $runs,
            'operation_ids' => $operations->pluck('id')->all(), 'retain_billing_user_ids' => $retained,
            'ledger_untouched' => true, 'owned_counts' => array_map('count', $owned), 'writes_executed' => false];
    }

    public function cleanup(string $batch, string $end, WorkReportFixtureManifest $store, string $approval, bool $writersPaused): array
    {
        $identity = $this->target->verify();
        if (! $writersPaused) {
            throw new LogicException('FIXTURE_CLEANUP_REQUIRES_PAUSED_WRITERS_AND_WORKERS');
        }

        return $store->exclusive(function () use ($batch, $end, $store, $approval, $identity) {
            return DB::transaction(function () use ($batch, $end, $store, $approval, $identity) {
                $manifest = $store->read() ?? throw new LogicException('FIXTURE_NOT_APPLIED');
                if ($manifest['identity'] !== $identity || ! hash_equals($manifest['fingerprint'], $approval)) {
                    throw new LogicException('FIXTURE_APPROVAL_FINGERPRINT_MISMATCH');
                }
                DB::table('organizations')->where('id', $manifest['organization_id'])->lockForUpdate()->firstOrFail();
                if ($manifest['state'] === 'cleaned') {
                    if ($manifest['plan']['batch'] !== $batch || $manifest['plan']['period']['end_date'] !== $end) {
                        throw new LogicException('FIXTURE_MANIFEST_DIFFERENT_OR_RETIRED_BATCH');
                    }
                    foreach (['daily_reports', 'daily_report_items', 'departments'] as $table) {
                        if (DB::table($table)->whereIn('id', array_column($manifest['owned'][$table], 'id'))->exists()) {
                            throw new LogicException('FIXTURE_CLEANUP_COMMIT_REQUIRES_RECOVERY');
                        }
                    }
                    $retained = $manifest['retained_user_ids'];
                    if (DB::table('users')->whereIn('id', array_values($manifest['users']))->whereNotIn('id', $retained)->exists()
                        || DB::table('organization_memberships')->whereIn('user_id', $retained)->where('is_active', true)->exists()
                        || DB::table('work_report_analyses')->whereIn('requested_by_user_id', array_values($manifest['users']))->exists()) {
                        throw new LogicException('FIXTURE_CLEANUP_COMMIT_REQUIRES_RECOVERY');
                    }

                    return ['status' => 'PASS', 'mode' => 'cleanup', 'reused' => true, 'ledger_untouched' => true];
                }
                $plan = $this->cleanupPlan($batch, $end, $store);
                $this->approve($plan, $approval);
                $users = array_values($manifest['users']);
                $memberships = array_column($manifest['owned']['organization_memberships'], 'id');
                DB::table('work_report_regeneration_requests')->whereIn('id', $plan['operation_ids'])->delete();
                DB::table('work_report_analysis_revisions')->whereIn('analysis_id', $plan['analysis_ids'])->delete();
                DB::table('work_report_analysis_units')->whereIn('work_report_analysis_id', $plan['analysis_ids'])->delete();
                DB::table('work_report_analyses')->whereIn('id', $plan['analysis_ids'])->delete();
                DB::table('daily_report_items')->whereIn('id', array_column($manifest['owned']['daily_report_items'], 'id'))->delete();
                DB::table('daily_reports')->whereIn('id', array_column($manifest['owned']['daily_reports'], 'id'))->delete();
                DB::table('membership_roles')->whereIn('membership_id', $memberships)->delete();
                $retained = $plan['retain_billing_user_ids'];
                DB::table('organization_memberships')->whereIn('id', $memberships)->whereNotIn('user_id', $retained)->delete();
                DB::table('organization_memberships')->whereIn('id', $memberships)->whereIn('user_id', $retained)->update(['is_active' => false, 'department_id' => null]);
                if (Schema::hasTable('sessions')) {
                    DB::table('sessions')->whereIn('user_id', $users)->delete();
                }
                if (Schema::hasTable('personal_access_tokens')) {
                    DB::table('personal_access_tokens')->where('tokenable_type', User::class)->whereIn('tokenable_id', $users)->delete();
                }
                DB::table('users')->whereIn('id', $users)->whereNotIn('id', $retained)->delete();
                foreach ($retained as $id) {
                    DB::table('users')->where('id', $id)->update(['password' => Hash::make(bin2hex(random_bytes(32))), 'remember_token' => null]);
                }
                DB::table('departments')->whereIn('id', array_values($manifest['departments']))->delete();
                $store->write([...$manifest, 'state' => 'cleaned', 'retained_user_ids' => $retained, 'cleaned_at' => now()->toIso8601String()]);

                return ['status' => 'PASS', 'mode' => 'cleanup', 'reused' => false, 'retained_user_ids' => $retained, 'ledger_untouched' => true];
            });
        });
    }

    private function approve(array $preview, string $approval): void
    {
        if (! preg_match('/\A[a-f0-9]{64}\z/D', $approval) || ! hash_equals($preview['fingerprint'], $approval)) {
            throw new LogicException('FIXTURE_APPROVAL_FINGERPRINT_MISMATCH');
        }
    }

    private function verifyRows(array $manifest): void
    {
        foreach ($manifest['owned'] as $table => $rows) {
            $ids = array_column($rows, 'id');
            foreach ($rows as $row) {
                $query = DB::table($table);
                foreach ($row as $key => $value) {
                    $query->where($key, $value);
                }
                $actual = $query->first();
                if ($actual === null || ! collect($row)->every(fn ($value, $key) => $value === null ? $actual->$key === null : (string) $actual->$key === (string) $value)) {
                    throw new LogicException('FIXTURE_OWNED_ROW_CHANGED_OR_MISSING');
                }
            }
            // No unknown reports/items/memberships may piggyback on an owned parent.
            $parents = match ($table) {
                'daily_reports' => ['user_id', array_values($manifest['users'])],
                'daily_report_items' => ['daily_report_id', array_column($manifest['owned']['daily_reports'], 'id')],
                'organization_memberships' => ['user_id', array_values($manifest['users'])],
                default => null,
            };
            if ($parents !== null && DB::table($table)->whereIn($parents[0], $parents[1])->whereNotIn('id', $ids)->exists()) {
                throw new LogicException('FIXTURE_UNOWNED_DEPENDENT_ROWS');
            }
        }
    }
}
