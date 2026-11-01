<?php

namespace Tests\Feature;

use App\Support\StagingBootstrapPlan;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class WorkReportRegenerationMigrationTest extends TestCase
{
    public function test_m24_is_additive_preserves_every_prior_table_and_has_durable_unique_keys(): void
    {
        $prior = array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 23);
        $this->artisan('migrate', ['--path' => array_map(fn ($m) => 'database/migrations/'.$m.'.php', $prior), '--force' => true])->assertSuccessful();
        DB::table('users')->insert(['name' => 'Preserve prior rows', 'email' => 'preserve@example.test', 'password' => 'synthetic']);
        $before = [];
        foreach (Schema::getTableListing() as $table) {
            if (! in_array(basename(str_replace('.', '/', $table)), ['migrations', 'sqlite_sequence'], true)) {
                $before[$table] = DB::table($table)->get()->toJson();
            }
        }
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_11_02_010000_create_work_report_regeneration_tables.php', '--force' => true])->assertSuccessful();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
        }
        $this->assertSame(StagingBootstrapPlan::EXPECTED_MIGRATIONS, DB::table('migrations')->orderBy('id')->pluck('migration')->all());
        $operation = collect(Schema::getIndexes('work_report_regeneration_requests'))->firstWhere('name', 'work_regeneration_operation_unique');
        $this->assertTrue($operation['unique']);
        $this->assertSame(['organization_id', 'requested_by_user_id', 'idempotency_key'], $operation['columns']);
        $this->assertTrue(collect(Schema::getIndexes('work_report_analysis_revisions'))->firstWhere('name', 'work_revision_number_unique')['unique']);
    }

    public function test_mysql_ddl_compiles_without_shared_database_io_and_empty_rollback_is_safe(): void
    {
        $mysql = new class(fn () => throw new RuntimeException('No shared database allowed'), 'compile_only', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']) extends MySqlConnection
        {
            public function isMaria()
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.32';
            }
        };
        $original = Schema::getFacadeRoot();
        $migration = require database_path('migrations/2026_11_02_010000_create_work_report_regeneration_tables.php');
        try {
            Schema::swap($mysql->getSchemaBuilder());
            $queries = $mysql->pretend(fn () => $migration->up());
        } finally {
            Schema::swap($original);
        }
        $sql = implode("\n", array_column($queries, 'query'));
        $this->assertStringContainsString('`review_feedback` longtext null', $sql);
        $this->assertStringContainsString('work_regeneration_operation_unique', $sql);
        $this->assertStringNotContainsString('alter table `work_report_analyses`', $sql);
        $this->assertStringNotContainsString('delete from', $sql);
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $migration->down();
        $this->assertFalse(Schema::hasTable('work_report_analysis_revisions'));
        $this->assertFalse(Schema::hasTable('work_report_regeneration_requests'));
    }
}
