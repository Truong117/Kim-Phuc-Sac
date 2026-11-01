<?php

namespace Tests\Feature;

use App\Support\StagingBootstrapPlan;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class WorkReportAnalysisMigrationTest extends TestCase
{
    public function test_additive_migration_preserves_all_previous_rows_and_has_atomic_cache_constraint(): void
    {
        $prior = array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 22);
        $this->artisan('migrate', ['--path' => array_map(fn ($m) => 'database/migrations/'.$m.'.php', $prior), '--force' => true])->assertSuccessful();
        DB::table('users')->insert(['name' => 'Preserve existing user', 'email' => 'preserve@example.test', 'password' => 'synthetic-only']);
        $before = [];
        foreach (Schema::getTableListing() as $table) {
            if (! in_array(basename(str_replace('.', '/', $table)), ['migrations', 'sqlite_sequence'], true)) {
                $before[$table] = DB::table($table)->get()->toJson();
            }
        }
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_11_02_000000_create_work_report_analyses.php', '--force' => true])->assertSuccessful();
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table);
        }
        $this->assertSame(StagingBootstrapPlan::EXPECTED_MIGRATIONS, DB::table('migrations')->orderBy('id')->pluck('migration')->all());
        $unique = collect(Schema::getIndexes('work_report_analyses'))->firstWhere('name', 'work_analysis_cache_unique');
        $this->assertTrue($unique['unique']);
        $this->assertSame(['cache_key'], $unique['columns']);
        $this->assertDatabaseCount('work_report_analyses', 0);
        $this->assertDatabaseCount('work_report_analysis_units', 0);
    }

    public function test_mysql_ddl_is_additive_with_unsigned_keys_and_longtext_encryption_storage(): void
    {
        $mysql = new class(fn () => throw new RuntimeException('No shared DB access allowed'), 'compile_only', '', ['driver' => 'mysql', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']) extends MySqlConnection
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
        try {
            Schema::swap($mysql->getSchemaBuilder());
            $migration = require database_path('migrations/2026_11_02_000000_create_work_report_analyses.php');
            $queries = $mysql->pretend(fn () => $migration->up());
        } finally {
            Schema::swap($original);
        }
        $sql = implode("\n", array_column($queries, 'query'));
        $this->assertStringContainsString('`snapshot` longtext not null', $sql);
        $this->assertStringContainsString('`organization_id` bigint unsigned not null', $sql);
        $this->assertStringContainsString('add unique `work_analysis_cache_unique`', $sql);
        $this->assertStringNotContainsString('alter table `ai_daily_report_runs`', $sql);
        $this->assertStringNotContainsString('delete from', $sql);
        $this->assertStringNotContainsString('insert into', $sql);
    }
}
