<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\WeeklyPlanningPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningPermissionSeederTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_seeder_is_idempotent_additive_and_grants_admin_view_only(): void
    {
        $owner = Role::query()->create(['code' => 'OWNER', 'name' => 'Owner', 'is_system' => true]);
        $hr = Role::query()->create(['code' => 'HR_MANAGER', 'name' => 'HR', 'is_system' => true]);
        $admin = Role::query()->create(['code' => 'ADMIN', 'name' => 'Admin', 'is_system' => true]);
        $unrelated = Permission::query()->create([
            'code' => 'unrelated.keep',
            'name' => 'Keep',
            'module' => 'test',
        ]);
        $hr->permissions()->attach($unrelated->id, ['data_scope' => null]);

        $this->seed(WeeklyPlanningPermissionSeeder::class);
        $this->seed(WeeklyPlanningPermissionSeeder::class);

        $this->assertTrue($owner->fresh()->permissions()->where('code', 'weekly_plans.publish')->exists());
        $this->assertTrue($hr->fresh()->permissions()->where('code', 'weekly_plans.manage_draft')->exists());
        $this->assertTrue($hr->fresh()->permissions()->where('code', 'unrelated.keep')->exists());
        $adminWeeklyPermissions = $admin->fresh()->permissions()->where('code', 'like', 'weekly_plans.%')->get();
        $this->assertCount(1, $adminWeeklyPermissions);
        $this->assertSame('weekly_plans.view', $adminWeeklyPermissions->sole()->code);
        $this->assertDatabaseHas('role_permissions', [
            'role_id' => $admin->id,
            'permission_id' => $adminWeeklyPermissions->sole()->id,
            'data_scope' => DataScope::ALL->value,
        ]);
        $this->assertSame(6, Permission::query()->where('code', 'like', 'weekly_plans.%')->count());
    }
}
