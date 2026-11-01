<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationGoogleDocsFolder;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class GoogleDocsRegistryMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_schema_has_required_columns_indexes_and_restricting_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasColumns('organization_google_docs_folders', [
            'id',
            'organization_id',
            'google_folder_id',
            'is_active',
            'approved_by_user_id',
            'approved_at',
            'created_at',
            'updated_at',
        ]));
        $this->assertTrue(Schema::hasColumns('organization_google_docs_folder_events', [
            'id',
            'folder_id',
            'organization_id',
            'actor_user_id',
            'event_type',
            'previous_google_folder_id',
            'current_google_folder_id',
            'created_at',
        ]));

        $folderIndexes = collect(DB::select("PRAGMA index_list('organization_google_docs_folders')"))
            ->pluck('name');
        $this->assertTrue($folderIndexes->contains('org_google_docs_folders_org_unique'));
        $this->assertTrue($folderIndexes->contains('org_google_docs_folders_folder_unique'));
        $this->assertTrue($folderIndexes->contains('org_google_docs_folders_active_idx'));

        $folderForeignKeys = collect(DB::select("PRAGMA foreign_key_list('organization_google_docs_folders')"));
        $this->assertSame(
            ['organizations', 'users'],
            $folderForeignKeys->pluck('table')->sort()->values()->all(),
        );
        $this->assertSame(
            ['RESTRICT'],
            $folderForeignKeys->pluck('on_delete')->unique()->values()->all(),
        );

        $eventForeignKeys = collect(DB::select("PRAGMA foreign_key_list('organization_google_docs_folder_events')"));
        $this->assertSame(
            ['organization_google_docs_folders', 'organizations', 'users'],
            $eventForeignKeys->pluck('table')->sort()->values()->all(),
        );
        $this->assertSame(['RESTRICT'], $eventForeignKeys->pluck('on_delete')->unique()->values()->all());
    }

    public function test_one_folder_per_organization_and_one_organization_per_folder_are_enforced(): void
    {
        $actor = User::factory()->create();
        $firstOrganization = Organization::query()->create([
            'code' => 'GOOGLE_A',
            'name' => 'Google A',
            'type' => 'internal',
            'is_active' => true,
        ]);
        $secondOrganization = Organization::query()->create([
            'code' => 'GOOGLE_B',
            'name' => 'Google B',
            'type' => 'internal',
            'is_active' => true,
        ]);

        OrganizationGoogleDocsFolder::query()->create([
            'organization_id' => $firstOrganization->id,
            'google_folder_id' => 'ApprovedFolder_1234567890_AAAAA',
            'is_active' => true,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
        ]);

        try {
            OrganizationGoogleDocsFolder::query()->create([
                'organization_id' => $firstOrganization->id,
                'google_folder_id' => 'ApprovedFolder_1234567890_BBBBB',
                'is_active' => true,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
            ]);
            $this->fail('An organization must not have two approved folder records.');
        } catch (QueryException) {
            $this->assertDatabaseCount('organization_google_docs_folders', 1);
        }

        try {
            OrganizationGoogleDocsFolder::query()->create([
                'organization_id' => $secondOrganization->id,
                'google_folder_id' => 'ApprovedFolder_1234567890_AAAAA',
                'is_active' => true,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
            ]);
            $this->fail('A Google folder must not be shared by two organizations.');
        } catch (QueryException) {
            $this->assertDatabaseCount('organization_google_docs_folders', 1);
        }
    }

    public function test_migration_rolls_back_in_dependency_order_and_can_be_reapplied_without_touching_tasks(): void
    {
        $taskColumns = Schema::getColumnListing('tasks');
        $migration = require database_path(
            'migrations/2026_10_26_000000_create_organization_google_docs_folder_tables.php',
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('organization_google_docs_folder_events'));
        $this->assertFalse(Schema::hasTable('organization_google_docs_folders'));
        $this->assertSame($taskColumns, Schema::getColumnListing('tasks'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('organization_google_docs_folders'));
        $this->assertTrue(Schema::hasTable('organization_google_docs_folder_events'));
        $this->assertSame($taskColumns, Schema::getColumnListing('tasks'));
    }

    public function test_existing_schema_missing_unique_index_fails_closed(): void
    {
        Schema::table('organization_google_docs_folders', function (Blueprint $table): void {
            $table->dropUnique('org_google_docs_folders_folder_unique');
        });

        $this->assertMigrationRejected();
    }

    public function test_existing_schema_missing_foreign_key_fails_closed(): void
    {
        Schema::table('organization_google_docs_folders', function (Blueprint $table): void {
            $table->dropForeign(['approved_by_user_id']);
        });

        $this->assertMigrationRejected();
    }

    public function test_existing_schema_missing_column_fails_closed(): void
    {
        Schema::table('organization_google_docs_folders', function (Blueprint $table): void {
            $table->dropColumn('approved_at');
        });

        $this->assertMigrationRejected();
    }

    public function test_partial_ddl_state_is_not_automatically_repaired(): void
    {
        Schema::drop('organization_google_docs_folder_events');

        $this->assertMigrationRejected();
        $this->assertTrue(Schema::hasTable('organization_google_docs_folders'));
        $this->assertFalse(Schema::hasTable('organization_google_docs_folder_events'));
    }

    public function test_failed_schema_validation_does_not_mark_migration_as_ran(): void
    {
        $migrationName = '2026_10_26_000000_create_organization_google_docs_folder_tables';
        DB::table('migrations')->where('migration', $migrationName)->delete();
        Schema::table('organization_google_docs_folders', function (Blueprint $table): void {
            $table->dropUnique('org_google_docs_folders_folder_unique');
        });

        try {
            Artisan::call('migrate', [
                '--path' => 'database/migrations/'.$migrationName.'.php',
                '--force' => true,
            ]);
            $this->fail('The migration command must fail for an incomplete schema.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Existing Google Docs registry schema detected',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseMissing('migrations', ['migration' => $migrationName]);
        $this->assertFalse(Schema::hasIndex(
            'organization_google_docs_folders',
            'org_google_docs_folders_folder_unique',
        ));
    }

    private function assertMigrationRejected(): void
    {
        try {
            $this->migration()->up();
            $this->fail('The migration must reject an incomplete existing schema.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'Existing Google Docs registry schema detected',
                $exception->getMessage(),
            );
        }
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_10_26_000000_create_organization_google_docs_folder_tables.php',
        );
    }
}
