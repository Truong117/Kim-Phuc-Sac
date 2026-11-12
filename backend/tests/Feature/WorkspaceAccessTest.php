<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Database\Seeders\WorkspacePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WorkspaceAccessTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_workspace_access_requires_authentication_and_active_kps_membership(): void
    {
        $this->getJson('/api/workspaces')->assertUnauthorized();
        $user = User::factory()->create();
        $this->createMembership($user, membershipIsActive: false);
        $this->actingAs($user, 'web')->getJson('/api/workspaces')->assertUnauthorized();
    }

    #[DataProvider('roles')]
    public function test_workspace_capabilities_and_landing_are_resolved_on_the_server(string $code, array $ids, string $home): void
    {
        $this->seed([AuthorizationSeeder::class, WorkspacePermissionSeeder::class]);
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $this->attachRole($membership['membership_id'], DB::table('roles')->where('code', $code)->value('id'), true);
        $response = $this->actingAs($user, 'web')->getJson('/api/workspaces')->assertOk();
        $this->assertSame($ids, array_column($response->json('workspaces'), 'id'));
        $this->assertSame($home, collect($response->json('workspaces'))->last()['home']);
        if ($code === 'KPS_SPA_TECHNICIAN') {
            $response->assertJsonPath('workspaces.0.sections', ['technician']);
        }
    }

    public static function roles(): array
    {
        return [
            ['OFFICE_STAFF', ['internal'], '/'],
            ['OWNER', ['internal', 'spa'], '/spa'],
            ['ADMIN', ['internal', 'spa'], '/spa'],
            ['EXECUTIVE_ASSISTANT', ['internal', 'spa'], '/spa'],
            ['KPS_SPA_MANAGER', ['spa'], '/spa'],
            ['KPS_SPA_STAFF', ['spa'], '/spa/reception'],
            ['KPS_SPA_RECEPTION', ['spa'], '/spa/reception'],
            ['KPS_SPA_DISPATCH', ['spa'], '/spa/reception'],
            ['KPS_SPA_TECHNICIAN', ['spa'], '/spa/technician'],
        ];
    }

    public function test_multiple_roles_union_workspaces_without_using_primary_role_or_display_name(): void
    {
        $this->seed([AuthorizationSeeder::class, WorkspacePermissionSeeder::class]);
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        foreach (['KPS_SPA_TECHNICIAN', 'OFFICE_STAFF'] as $index => $code) {
            $roleId = DB::table('roles')->where('code', $code)->value('id');
            DB::table('roles')->where('id', $roleId)->update(['name' => 'Tên hiển thị tùy chỉnh']);
            $this->attachRole($membership['membership_id'], $roleId, $index === 0);
        }
        $this->actingAs($user, 'web')->getJson('/api/workspaces')->assertOk()
            ->assertJsonPath('workspaces.0.id', 'internal')
            ->assertJsonPath('workspaces.1.id', 'spa')
            ->assertJsonPath('workspaces.1.sections', ['technician']);
    }

    public function test_workspace_seeder_is_additive_and_idempotent_and_does_not_assign_accounts(): void
    {
        $this->seed([AuthorizationSeeder::class, WorkspacePermissionSeeder::class]);
        $before = DB::table('role_permissions')->count();
        $this->seed(WorkspacePermissionSeeder::class);
        $this->assertSame($before, DB::table('role_permissions')->count());
        $this->assertSame(0, DB::table('membership_roles')->count());
    }

    public function test_no_role_has_no_workspace_and_settings_permission_does_not_grant_spa(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $this->actingAs($user, 'web')->getJson('/api/workspaces')->assertOk()->assertExactJson(['workspaces' => []]);
        $role = $this->createRole('CUSTOM_OFFICE', 'Owner');
        $this->attachRole($membership['membership_id'], $role);
        $this->grantPermission($role, $this->createPermission('settings.view'), null);
        $this->getJson('/api/workspaces')->assertOk()->assertExactJson(['workspaces' => [['id' => 'internal', 'home' => '/', 'sections' => []]]]);
    }
}
