<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntegrationMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_additive_schema_and_rollback_preserve_existing_user_data(): void
    {
        $user = User::factory()->create();
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        $migration = require database_path('migrations/2026_11_05_000000_create_integration_entries_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('integration_entries'));
        $this->assertDatabaseHas('users', ['id' => $user->id]);
        $migration->up();
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertDatabaseCount('integration_entries', 0);
        $this->assertTrue(Schema::hasColumns('integration_entries', ['organization_id', 'responsible_user_id', 'version', 'url_hash', 'created_by', 'updated_by']));
        $index = collect(Schema::getIndexes('integration_entries'))->firstWhere('name', 'integration_entries_url_unique');
        $this->assertNotNull($index);
        $this->assertTrue($index['unique']);
        $this->assertSame(['organization_id', 'kind', 'url_hash'], $index['columns']);
        $this->assertCount(4, Schema::getForeignKeys('integration_entries'));
    }
}
