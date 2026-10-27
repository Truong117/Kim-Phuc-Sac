<?php

namespace Tests\Feature;

use App\Enums\WeeklyPlanSourceType;
use App\Enums\WeeklyPlanStatus;
use App\Models\Organization;
use App\Models\User;
use App\Models\WeeklyPlan;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class GoogleDocsEmployeeMappingMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const EVENTS_TABLE = 'weekly_plan_google_docs_tab_mapping_events';

    private const MAPPINGS_TABLE = 'weekly_plan_google_docs_tab_mappings';

    private const MIGRATION = '2026_10_27_010000_create_weekly_plan_google_docs_employee_mapping_tables';

    public function test_fresh_schema_has_plan_specific_identity_audit_indexes_and_restrict_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasColumns(self::MAPPINGS_TABLE, [
            'organization_id',
            'weekly_plan_id',
            'document_id_sha256',
            'tab_id_sha256',
            'tab_title_sha256',
            'status',
            'assignee_user_id',
            'verified_by_user_id',
            'verified_at',
            'version',
        ]));
        $this->assertTrue(Schema::hasColumns(self::EVENTS_TABLE, [
            'mapping_id',
            'organization_id',
            'weekly_plan_id',
            'actor_user_id',
            'previous_status',
            'new_status',
            'previous_assignee_user_id',
            'new_assignee_user_id',
            'tab_title_sha256',
            'mapping_version',
        ]));

        $mappingIndexes = collect(DB::select("PRAGMA index_list('".self::MAPPINGS_TABLE."')"));
        $this->assertNotNull($mappingIndexes->first(
            fn (object $index): bool => $index->name === 'weekly_gdoc_tab_maps_plan_tab_unique'
                && (int) $index->unique === 1,
        ));

        $mappingForeignKeys = collect(DB::select("PRAGMA foreign_key_list('".self::MAPPINGS_TABLE."')"));
        $eventForeignKeys = collect(DB::select("PRAGMA foreign_key_list('".self::EVENTS_TABLE."')"));
        $this->assertCount(4, $mappingForeignKeys);
        $this->assertCount(6, $eventForeignKeys);
        $this->assertTrue($mappingForeignKeys->every(
            fn (object $foreign): bool => mb_strtoupper($foreign->on_delete) === 'RESTRICT',
        ));
        $this->assertTrue($eventForeignKeys->every(
            fn (object $foreign): bool => mb_strtoupper($foreign->on_delete) === 'RESTRICT',
        ));
    }

    public function test_missing_migration_sixteen_prerequisites_fail_before_schema_or_ledger_changes(): void
    {
        $this->dropMappingTables();
        Schema::drop('weekly_plan_google_docs_sources');
        Schema::table('weekly_plans', function (Blueprint $table): void {
            $table->dropColumn('source_type');
        });
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        try {
            $this->migration()->up();
            $this->fail('Migration unexpectedly accepted missing migration 16 prerequisites.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('prerequisites are missing', $exception->getMessage());
        }
        $this->assertFalse(Schema::hasTable(self::MAPPINGS_TABLE));
        $this->assertFalse(Schema::hasTable(self::EVENTS_TABLE));
        $this->assertDatabaseMissing('migrations', ['migration' => self::MIGRATION]);
    }

    public function test_migration_fails_closed_when_only_mapping_table_exists(): void
    {
        $this->dropMappingTables();
        Schema::create(self::MAPPINGS_TABLE, fn (Blueprint $table) => $table->id());

        $this->assertPartialSchemaFailsClosed('mappings=present, events=missing');
    }

    public function test_migration_fails_closed_when_only_event_table_exists(): void
    {
        $this->dropMappingTables();
        Schema::create(self::EVENTS_TABLE, fn (Blueprint $table) => $table->id());

        $this->assertPartialSchemaFailsClosed('mappings=missing, events=present');
    }

    public function test_migration_fails_closed_when_both_tables_already_exist(): void
    {
        $this->assertPartialSchemaFailsClosed('mappings=present, events=present');
    }

    public function test_plan_tab_unique_constraint_rejects_duplicate_mapping(): void
    {
        $fixture = $this->mappingFixture();

        $this->expectException(QueryException::class);
        DB::table(self::MAPPINGS_TABLE)->insert($fixture['mapping']);
    }

    public function test_restrict_foreign_key_prevents_deleting_a_plan_with_verified_mapping(): void
    {
        $fixture = $this->mappingFixture();

        $this->expectException(QueryException::class);
        DB::table('weekly_plans')->where('id', $fixture['plan_id'])->delete();
    }

    public function test_rollback_refuses_mapping_data(): void
    {
        $this->mappingFixture();

        try {
            $this->migration()->down();
            $this->fail('Rollback unexpectedly deleted verified mapping data.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback refused', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable(self::MAPPINGS_TABLE));
        $this->assertTrue(Schema::hasTable(self::EVENTS_TABLE));
    }

    public function test_rollback_refuses_audit_event_data(): void
    {
        $fixture = $this->mappingFixture();
        DB::table(self::EVENTS_TABLE)->insert([
            'mapping_id' => $fixture['mapping_id'],
            'organization_id' => $fixture['organization_id'],
            'weekly_plan_id' => $fixture['plan_id'],
            'actor_user_id' => $fixture['user_id'],
            'previous_status' => null,
            'new_status' => 'VERIFIED',
            'previous_assignee_user_id' => null,
            'new_assignee_user_id' => $fixture['user_id'],
            'tab_title_sha256' => hash('sha256', 'tab'),
            'mapping_version' => 1,
            'created_at' => now(),
        ]);

        try {
            $this->migration()->down();
            $this->fail('Rollback unexpectedly deleted mapping audit data.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback refused', $exception->getMessage());
        }

        $this->assertDatabaseCount(self::EVENTS_TABLE, 1);
    }

    private function assertPartialSchemaFailsClosed(string $evidence): void
    {
        try {
            $this->migration()->up();
            $this->fail('Migration unexpectedly accepted an existing or partial schema.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('will not accept or repair partial DDL', $exception->getMessage());
            $this->assertStringContainsString($evidence, $exception->getMessage());
        }
    }

    /** @return array{mapping_id: int, mapping: array<string, mixed>, organization_id: int, plan_id: int, user_id: int} */
    private function mappingFixture(): array
    {
        $organization = Organization::query()->create([
            'code' => 'MAPPING_SCHEMA_'.fake()->unique()->numerify('#####'),
            'name' => 'Mapping Schema Test',
            'type' => 'internal',
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $plan = WeeklyPlan::query()->create([
            'organization_id' => $organization->id,
            'created_by' => $user->id,
            'week_start' => '2026-11-02',
            'week_end' => '2026-11-07',
            'status' => WeeklyPlanStatus::DRAFT,
            'source_type' => WeeklyPlanSourceType::GOOGLE_DOCS,
            'original_filename' => 'Google Docs',
            'source_extension' => 'gdoc',
            'source_mime' => 'application/vnd.google-apps.document',
            'source_size' => 0,
            'source_sha256' => hash('sha256', fake()->uuid()),
            'parse_status' => 'PARSED',
            'uploaded_at' => now(),
        ]);
        $mapping = [
            'organization_id' => $organization->id,
            'weekly_plan_id' => $plan->id,
            'document_id_sha256' => hash('sha256', 'document'),
            'tab_id_sha256' => hash('sha256', 'tab'),
            'tab_title_sha256' => hash('sha256', 'tab'),
            'status' => 'VERIFIED',
            'assignee_user_id' => $user->id,
            'verified_by_user_id' => $user->id,
            'verified_at' => now(),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $mappingId = DB::table(self::MAPPINGS_TABLE)->insertGetId($mapping);

        return [
            'mapping_id' => $mappingId,
            'mapping' => $mapping,
            'organization_id' => $organization->id,
            'plan_id' => $plan->id,
            'user_id' => $user->id,
        ];
    }

    private function dropMappingTables(): void
    {
        Schema::dropIfExists(self::EVENTS_TABLE);
        Schema::dropIfExists(self::MAPPINGS_TABLE);
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION.'.php');
    }
}
