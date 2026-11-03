<?php

namespace Tests\Feature;

use App\Support\StagingBootstrapPlan;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class WorkReportV3MigrationTest extends TestCase
{
    public function test_m25_adds_nullable_fields_and_preserves_every_existing_value(): void
    {
        $prior = array_slice(StagingBootstrapPlan::EXPECTED_MIGRATIONS, 0, 24);
        $this->artisan('migrate', ['--path' => array_map(fn ($m) => 'database/migrations/'.$m.'.php', $prior), '--force' => true])->assertSuccessful();
        $org = DB::table('organizations')->insertGetId(['code' => 'V3_PRESERVE', 'name' => 'Synthetic']);
        $user = DB::table('users')->insertGetId(['name' => 'Preserve', 'email' => 'preserve-v3@example.test', 'password' => 'synthetic']);
        $report = DB::table('daily_reports')->insertGetId(['organization_id' => $org, 'user_id' => $user, 'report_date' => '2026-11-02', 'locked_at' => '2026-11-02 16:00:00']);
        $item = DB::table('daily_report_items')->insertGetId(['daily_report_id' => $report, 'content' => 'Giữ nội dung', 'result' => '2 video', 'note' => 'Ghi chú cũ', 'status' => 'COMPLETED', 'sort_order' => 1]);
        $beforeReport = (array) DB::table('daily_reports')->find($report);
        $beforeItem = (array) DB::table('daily_report_items')->find($item);
        $beforeUsers = DB::table('users')->get()->toJson();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_11_03_010000_add_daily_report_v3_fields.php', '--force' => true])->assertSuccessful();
        $afterReport = (array) DB::table('daily_reports')->find($report);
        $afterItem = (array) DB::table('daily_report_items')->find($item);
        $this->assertNull($afterReport['next_day_plan']);
        $this->assertNull($afterItem['issue']);
        $this->assertNull($afterItem['evidence_links']);
        $this->assertSame($beforeReport, array_intersect_key($afterReport, $beforeReport));
        $this->assertSame($beforeItem, array_intersect_key($afterItem, $beforeItem));
        $this->assertSame($beforeUsers, DB::table('users')->get()->toJson());
        $this->assertSame(StagingBootstrapPlan::EXPECTED_MIGRATIONS, DB::table('migrations')->orderBy('id')->pluck('migration')->all());
    }

    public function test_mysql_schema_compiles_as_additive_ddl_without_connecting_to_any_database(): void
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
        try {
            Schema::swap($mysql->getSchemaBuilder());
            $migration = require database_path('migrations/2026_11_03_010000_add_daily_report_v3_fields.php');
            $queries = $mysql->pretend(fn () => $migration->up());
        } finally {
            Schema::swap($original);
        }
        $sql = implode("\n", array_column($queries, 'query'));
        $this->assertStringContainsString('`next_day_plan` text null', $sql);
        $this->assertStringContainsString('`issue` text null', $sql);
        $this->assertStringContainsString('`evidence_links` json null', $sql);
        $this->assertStringNotContainsString('delete from', $sql);
        $this->assertStringNotContainsString('drop table', $sql);
    }
}
