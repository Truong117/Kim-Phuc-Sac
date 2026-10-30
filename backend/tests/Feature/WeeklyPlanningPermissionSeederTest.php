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

    public function test_seeder_is_idempotent_additive_and_grants_admin_view_and_folder_configuration(): void
    {
        $owner = Role::query()->create(['code' => 'OWNER', 'name' => 'Owner', 'is_system' => true]);
        $hr = Role::query()->create(['code' => 'HR_MANAGER', 'name' => 'HR', 'is_system' => true]);
        $admin = Role::query()->create(['code' => 'ADMIN', 'name' => 'Admin', 'is_system' => true]);
        $assistant = Role::query()->create([
            'code' => 'EXECUTIVE_ASSISTANT',
            'name' => 'Trợ lý Giám đốc',
            'is_system' => true,
        ]);
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
        $this->assertTrue($assistant->fresh()->permissions()->where('code', 'weekly_plans.assistant_review')->exists());
        $this->assertTrue($assistant->fresh()->permissions()->where('code', 'weekly_plans.view')->exists());
        $this->assertFalse($assistant->fresh()->permissions()->where('code', 'weekly_plans.publish')->exists());
        $adminWeeklyPermissions = $admin->fresh()->permissions()->where('code', 'like', 'weekly_plans.%')->get();
        $this->assertCount(2, $adminWeeklyPermissions);
        foreach (['weekly_plans.view', 'weekly_plans.google_docs.configure'] as $code) {
            $permission = $adminWeeklyPermissions->firstWhere('code', $code);
            $this->assertNotNull($permission);
            $this->assertDatabaseHas('role_permissions', [
                'role_id' => $admin->id,
                'permission_id' => $permission->id,
                'data_scope' => DataScope::ALL->value,
            ]);
        }
        $this->assertFalse($hr->fresh()->permissions()->where('code', 'weekly_plans.google_docs.configure')->exists());
        $this->assertFalse($owner->fresh()->permissions()->where('code', 'weekly_plans.google_docs.configure')->exists());
        $this->assertSame(8, Permission::query()->where('code', 'like', 'weekly_plans.%')->count());
    }
}
