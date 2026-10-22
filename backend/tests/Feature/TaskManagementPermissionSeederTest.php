<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\TaskManagementPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TaskManagementPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_targeted_seeder_is_idempotent_and_preserves_unrelated_grants(): void
    {
        $this->seed(AuthorizationSeeder::class);
        $permissionId = (int) DB::table('permissions')->where('code', 'tasks.status.update')->value('id');
        $ownerId = (int) DB::table('roles')->where('code', 'OWNER')->value('id');
        $managerId = (int) DB::table('roles')->where('code', 'DEPARTMENT_MANAGER')->value('id');
        $salesManagerId = (int) DB::table('roles')->where('code', 'SALES_MANAGER')->value('id');
        $customPermissionId = DB::table('permissions')->insertGetId([
            'code' => 'custom.task.preserved',
            'name' => 'Custom task preserved',
            'module' => 'custom',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_permissions')->insert([
            'role_id' => $ownerId,
            'permission_id' => $customPermissionId,
            'data_scope' => DataScope::ORGANIZATION->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();

        $this->seed(TaskManagementPermissionSeeder::class);
        $countAfterFirstRun = DB::table('role_permissions')->count();
        $this->seed(TaskManagementPermissionSeeder::class);

        $this->assertSame($countAfterFirstRun, DB::table('role_permissions')->count());
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $ownerId,
            'permission_id' => $permissionId,
            'data_scope' => DataScope::ALL->value,
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $managerId,
            'permission_id' => $permissionId,
            'data_scope' => DataScope::DEPARTMENT->value,
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $salesManagerId,
            'permission_id' => $permissionId,
            'data_scope' => DataScope::TEAM->value,
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $ownerId,
            'permission_id' => $customPermissionId,
            'data_scope' => DataScope::ORGANIZATION->value,
        ]);
    }
}
