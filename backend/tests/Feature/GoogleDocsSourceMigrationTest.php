<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class GoogleDocsSourceMigrationTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_fresh_schema_contains_source_type_table_indexes_and_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasColumn('weekly_plans', 'source_type'));
        $this->assertTrue(Schema::hasTable('weekly_plan_google_docs_sources'));
        $this->assertTrue(Schema::hasColumns('weekly_plan_google_docs_sources', [
            'weekly_plan_id',
            'organization_id',
            'approved_folder_id',
            'confirmed_by_user_id',
            'request_id',
            'request_payload_sha256',
            'google_document_id',
            'document_id_sha256',
            'approved_folder_id_sha256',
            'document_title',
            'revision_id',
            'snapshot_sha256',
            'tab_manifest',
            'passage_count',
            'character_count',
            'captured_at',
            'last_verified_at',
            'stale_at',
        ]));

        $this->assertUniqueIndex('weekly_plan_google_docs_sources', 'weekly_google_sources_plan_unique');
        $this->assertUniqueIndex('weekly_plan_google_docs_sources', 'weekly_google_sources_org_request_unique');
        $this->assertForeignKey('weekly_plan_google_docs_sources', 'weekly_plan_id', 'weekly_plans', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_google_docs_sources', 'organization_id', 'organizations', 'id', 'RESTRICT');
        $this->assertForeignKey(
            'weekly_plan_google_docs_sources',
            'approved_folder_id',
            'organization_google_docs_folders',
            'id',
            'RESTRICT',
        );
        $this->assertForeignKey('weekly_plan_google_docs_sources', 'confirmed_by_user_id', 'users', 'id', 'RESTRICT');
    }

    public function test_migration_preserves_existing_plans_with_uploaded_file_default_and_reapplies_cleanly(): void
    {
        $migration = $this->migration();
        $migration->down();
        [$organizationId, $user] = $this->organizationAndUser();
        $planId = $this->insertLegacyPlan($organizationId, $user->id);

        $migration->up();

        $this->assertDatabaseHas('weekly_plans', [
            'id' => $planId,
            'source_type' => 'UPLOADED_FILE',
            'original_filename' => 'existing.docx',
        ]);
        $this->assertTrue(Schema::hasTable('weekly_plan_google_docs_sources'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('weekly_plan_google_docs_sources'));
        $this->assertFalse(Schema::hasColumn('weekly_plans', 'source_type'));
        $this->assertDatabaseHas('weekly_plans', ['id' => $planId, 'original_filename' => 'existing.docx']);

        $migration->up();
        $this->assertDatabaseHas('weekly_plans', ['id' => $planId, 'source_type' => 'UPLOADED_FILE']);
    }

    public function test_partial_ddl_with_only_source_type_column_fails_closed(): void
    {
        $migration = $this->migration();
        $migration->down();
        Schema::table('weekly_plans', function ($table): void {
            $table->string('source_type', 32)->default('UPLOADED_FILE');
        });

        try {
            $migration->up();
            $this->fail('Partial DDL was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial DDL', $exception->getMessage());
            $this->assertFalse(Schema::hasTable('weekly_plan_google_docs_sources'));
        } finally {
            Schema::table('weekly_plans', function ($table): void {
                $table->dropColumn('source_type');
            });
            $migration->up();
        }
    }

    public function test_partial_ddl_with_only_source_table_fails_closed(): void
    {
        $migration = $this->migration();
        $migration->down();
        Schema::create('weekly_plan_google_docs_sources', function ($table): void {
            $table->id();
        });

        try {
            $migration->up();
            $this->fail('Partial source table was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('partial DDL', $exception->getMessage());
            $this->assertFalse(Schema::hasColumn('weekly_plans', 'source_type'));
        } finally {
            Schema::drop('weekly_plan_google_docs_sources');
            $migration->up();
        }
    }

    public function test_rollback_refuses_to_delete_persisted_google_sources(): void
    {
        $migration = $this->migration();
        [$organizationId, $user] = $this->organizationAndUser();
        $planId = $this->insertLegacyPlan($organizationId, $user->id);
        $folderId = DB::table('organization_google_docs_folders')->insertGetId([
            'organization_id' => $organizationId,
            'google_folder_id' => 'RollbackSafeFolder_1234567890',
            'is_active' => true,
            'approved_by_user_id' => $user->id,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('weekly_plan_google_docs_sources')->insert([
            'weekly_plan_id' => $planId,
            'organization_id' => $organizationId,
            'approved_folder_id' => $folderId,
            'confirmed_by_user_id' => $user->id,
            'request_id' => '8e11db8f-87d4-4cec-a5a5-aa5eab716d71',
            'request_payload_sha256' => str_repeat('1', 64),
            'google_document_id' => 'encrypted-value',
            'document_id_sha256' => str_repeat('2', 64),
            'approved_folder_id_sha256' => str_repeat('3', 64),
            'document_title' => 'encrypted-title',
            'revision_id' => null,
            'snapshot_sha256' => str_repeat('4', 64),
            'tab_manifest' => 'encrypted-manifest',
            'passage_count' => 0,
            'character_count' => 0,
            'captured_at' => now(),
            'last_verified_at' => now(),
            'stale_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $migration->down();
            $this->fail('Rollback deleted a persisted Google Docs source.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback refused', $exception->getMessage());
            $this->assertTrue(Schema::hasTable('weekly_plan_google_docs_sources'));
            $this->assertTrue(Schema::hasColumn('weekly_plans', 'source_type'));
        }
    }

    public function test_rollback_refuses_orphan_google_docs_plan_without_source_row(): void
    {
        $migration = $this->migration();
        [$organizationId, $user] = $this->organizationAndUser();
        $planId = $this->insertLegacyPlan($organizationId, $user->id);
        DB::table('weekly_plans')
            ->where('id', $planId)
            ->update(['source_type' => 'GOOGLE_DOCS']);

        try {
            $migration->down();
            $this->fail('Rollback removed source_type from an orphan GOOGLE_DOCS plan.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rollback refused', $exception->getMessage());
            $this->assertTrue(Schema::hasTable('weekly_plan_google_docs_sources'));
            $this->assertTrue(Schema::hasColumn('weekly_plans', 'source_type'));
            $this->assertDatabaseHas('weekly_plans', [
                'id' => $planId,
                'source_type' => 'GOOGLE_DOCS',
            ]);
        }
    }

    private function migration(): Migration
    {
        $migration = require database_path(
            'migrations/2026_10_27_000000_add_google_docs_source_to_weekly_plans.php',
        );
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    /** @return array{int, User} */
    private function organizationAndUser(): array
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);

        return [$membership['organization_id'], $user];
    }

    private function insertLegacyPlan(int $organizationId, int $userId): int
    {
        return DB::table('weekly_plans')->insertGetId([
            'organization_id' => $organizationId,
            'created_by' => $userId,
            'week_start' => '2026-11-02',
            'week_end' => '2026-11-07',
            'status' => 'DRAFT',
            'source_disk' => 'local',
            'source_path' => 'weekly-plans/existing.docx',
            'original_filename' => 'existing.docx',
            'source_extension' => 'docx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'source_size' => 1,
            'source_sha256' => str_repeat('f', 64),
            'parse_status' => 'PARSED',
            'uploaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function assertUniqueIndex(string $table, string $indexName): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('{$table}')"));
        $index = $indexes->first(fn (object $candidate): bool => $candidate->name === $indexName);

        $this->assertNotNull($index, "Missing index {$indexName} on {$table}.");
        $this->assertSame(1, (int) $index->unique, "Index {$indexName} is not unique.");
    }

    private function assertForeignKey(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $onDelete,
    ): void {
        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('{$table}')"));
        $foreignKey = $foreignKeys->first(
            fn (object $candidate): bool => $candidate->from === $column
                && $candidate->table === $referencedTable
                && $candidate->to === $referencedColumn,
        );

        $this->assertNotNull($foreignKey);
        $this->assertSame($onDelete, strtoupper((string) $foreignKey->on_delete));
    }
}
