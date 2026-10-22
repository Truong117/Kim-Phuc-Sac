<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use Database\Seeders\AiExecutiveDashboardPermissionSeeder;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiExecutiveDashboardPermissionSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_targeted_seeder_is_idempotent_and_preserves_unrelated_grants(): void
    {
        $this->seed(AuthorizationSeeder::class);

        $permissionId = (int) DB::table('permissions')
            ->where('code', 'ai.dashboard.view')
            ->value('id');
        $ownerId = (int) DB::table('roles')->where('code', 'OWNER')->value('id');
        $adminId = (int) DB::table('roles')->where('code', 'ADMIN')->value('id');
        $managerId = (int) DB::table('roles')->where('code', 'DEPARTMENT_MANAGER')->value('id');
        $customPermissionId = DB::table('permissions')->insertGetId([
            'code' => 'custom.preserved',
            'name' => 'Custom preserved grant',
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
        DB::table('role_permissions')
            ->where('permission_id', $permissionId)
            ->delete();
        $grantCountBefore = DB::table('role_permissions')->count();

        $this->seed(AiExecutiveDashboardPermissionSeeder::class);
        $this->seed(AiExecutiveDashboardPermissionSeeder::class);

        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $ownerId,
            'permission_id' => $permissionId,
            'data_scope' => DataScope::ALL->value,
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $adminId,
            'permission_id' => $permissionId,
            'data_scope' => DataScope::ALL->value,
        ]);
        $this->assertDatabaseMissing('role_permissions', [
            'role_id' => $managerId,
            'permission_id' => $permissionId,
        ]);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $ownerId,
            'permission_id' => $customPermissionId,
            'data_scope' => DataScope::ORGANIZATION->value,
        ]);
        $this->assertSame(
            $grantCountBefore + 2,
            DB::table('role_permissions')->count(),
        );
    }
}
