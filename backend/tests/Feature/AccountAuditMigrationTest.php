<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\StagingBootstrapPlan;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class AccountAuditMigrationTest extends TestCase
{
    use AuthorizationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $paths = array_map(static fn (string $migration): string => 'database/migrations/'.$migration.'.php', array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 21));
        $this->artisan('migrate', ['--path' => $paths, '--force' => true, '--no-interaction' => true])->assertSuccessful();
        $this->assertSame(array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 21), $this->ledger());
        $this->assertFalse(Schema::hasTable('account_audit_events'));
    }

    public function test_all_22_files_follow_the_exact_reviewed_order_without_missing_or_duplicate_steps(): void
    {
        $files = glob(database_path('migrations/*.php'));
        sort($files, SORT_STRING);
        $names = array_map(static fn (string $path): string => basename($path, '.php'), $files);
        $this->assertCount(22, $names);
        $this->assertCount(22, array_unique($names));
        $this->assertSame(StagingBootstrapPlan::EXPECTED_MIGRATIONS, $names);
        $this->applyAuditMigration();
        $this->assertSame($names, $this->ledger());
    }

    public function test_incremental_migration_preserves_existing_accounts_rbac_and_business_rows(): void
    {
        $user = User::factory()->create(['email' => 'existing@example.test']);
        $membership = $this->createMembership($user);
        $roleId = $this->createRole('M22_TEST_ROLE');
        $this->attachRole($membership['membership_id'], $roleId, true);
        DB::table('weekly_plans')->insert([
            'organization_id' => $membership['organization_id'], 'created_by' => $user->id,
            'week_start' => '2026-10-26', 'week_end' => '2026-10-31',
            'original_filename' => 'synthetic.docx', 'source_extension' => 'docx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'source_size' => 12, 'source_sha256' => str_repeat('a', 64), 'parse_status' => 'PARSED', 'uploaded_at' => now(),
        ]);
        $tables = array_values(array_filter(Schema::getTableListing(), static function (string $table): bool {
            $parts = explode('.', $table);

            return ! in_array(end($parts), ['migrations', 'sqlite_sequence'], true);
        }));
        $before = $this->fingerprints($tables);
        $this->applyAuditMigration();
        $this->assertSame($before, $this->fingerprints($tables));
        $this->assertSame(StagingBootstrapPlan::EXPECTED_MIGRATIONS, $this->ledger());
        $this->assertDatabaseCount('account_audit_events', 0);
        $this->assertSame(['id', 'event_type', 'actor_user_id', 'subject_user_id', 'previous_email', 'email', 'created_at'], Schema::getColumnListing('account_audit_events'));
        $this->assertSame([], Schema::getForeignKeys('account_audit_events'));
        $index = collect(Schema::getIndexes('account_audit_events'))->firstWhere('name', 'account_audit_events_subject_user_id_created_at_index');
        $this->assertNotNull($index);
        $this->assertSame(['subject_user_id', 'created_at'], $index['columns']);
        $this->assertFalse($index['unique']);
    }

    public function test_mysql_ddl_compiles_without_opening_a_mysql_or_tidb_connection(): void
    {
        $mysql = new MySqlConnection(static function (): never {
            throw new RuntimeException('MySQL/TiDB I/O is forbidden in this compilation test.');
        }, 'isolated_compile_only', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
        $original = Schema::getFacadeRoot();
        try {
            Schema::swap($mysql->getSchemaBuilder());
            $migration = require database_path('migrations/'.basename(StagingBootstrapPlan::EXPECTED_MIGRATIONS[21]).'.php');
            $queries = $mysql->pretend(static fn () => $migration->up());
        } finally {
            Schema::swap($original);
        }
        $sql = implode("\n", array_column($queries, 'query'));
        $this->assertCount(2, $queries);
        $this->assertStringContainsString('create table `account_audit_events`', $sql);
        $this->assertStringContainsString('`id` bigint unsigned not null auto_increment primary key', $sql);
        $this->assertStringContainsString('`event_type` varchar(64) not null', $sql);
        $this->assertStringContainsString('`actor_user_id` bigint unsigned not null', $sql);
        $this->assertStringContainsString('`subject_user_id` bigint unsigned not null', $sql);
        $this->assertStringContainsString('`created_at` timestamp not null', $sql);
        $this->assertStringContainsString('add index `account_audit_events_subject_user_id_created_at_index`(`subject_user_id`, `created_at`)', $sql);
        $this->assertStringNotContainsString('foreign key', $sql);
        $this->assertStringNotContainsString('alter table `users`', $sql);
        $this->assertStringNotContainsString('insert into', $sql);
    }

    public function test_empty_audit_can_roll_back_but_history_blocks_rollback(): void
    {
        $this->applyAuditMigration();
        $this->artisan('migrate:rollback', ['--path' => ['database/migrations/'.StagingBootstrapPlan::EXPECTED_MIGRATIONS[21].'.php'], '--step' => 1, '--force' => true])->assertSuccessful();
        $this->assertFalse(Schema::hasTable('account_audit_events'));
        $this->assertSame(array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 21), $this->ledger());
        $this->applyAuditMigration();
        DB::table('account_audit_events')->insert([
            'event_type' => 'account.email_changed', 'actor_user_id' => 42, 'subject_user_id' => 42,
            'previous_email' => 'old@example.test', 'email' => 'new@example.test', 'created_at' => now(),
        ]);
        $migration = require database_path('migrations/'.StagingBootstrapPlan::EXPECTED_MIGRATIONS[21].'.php');
        try {
            $migration->down();
            $this->fail('An audit history must not be dropped by rollback.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Cannot roll back committed account audit events', $error->getMessage());
        }
        $this->assertDatabaseCount('account_audit_events', 1);
        $this->assertSame(StagingBootstrapPlan::EXPECTED_MIGRATIONS, $this->ledger());
    }

    public function test_existing_partial_table_is_not_overwritten_or_added_to_the_ledger(): void
    {
        Schema::create('account_audit_events', static function (Blueprint $table): void {
            $table->id();
            $table->string('marker');
        });
        DB::table('account_audit_events')->insert(['marker' => 'preserve-existing-data']);
        $migration = require database_path('migrations/'.StagingBootstrapPlan::EXPECTED_MIGRATIONS[21].'.php');
        try {
            $migration->up();
            $this->fail('A pre-existing table must not be silently accepted or overwritten.');
        } catch (QueryException) {
            $this->assertDatabaseHas('account_audit_events', ['marker' => 'preserve-existing-data']);
        }
        $this->assertSame(array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 21), $this->ledger());
    }

    private function applyAuditMigration(): void
    {
        $this->artisan('migrate', ['--path' => ['database/migrations/'.StagingBootstrapPlan::EXPECTED_MIGRATIONS[21].'.php'], '--force' => true, '--no-interaction' => true])->assertSuccessful();
    }

    private function ledger(): array
    {
        return DB::table('migrations')->orderBy('id')->pluck('migration')->all();
    }

    private function fingerprints(array $tables): array
    {
        $fingerprints = [];
        foreach ($tables as $table) {
            $rows = array_map(static fn (object $row): string => json_encode($row, JSON_THROW_ON_ERROR), DB::table($table)->get()->all());
            sort($rows, SORT_STRING);
            $fingerprints[$table] = hash('sha256', implode("\n", $rows));
        }

        return $fingerprints;
    }
}
