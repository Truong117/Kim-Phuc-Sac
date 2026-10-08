<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class PermissionMiddlewareTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['auth:sanctum', 'permission:users.view'])
            ->get('/api/testing/permission', static fn () => response()->json(['authorized' => true]));
    }

    public function test_unauthenticated_request_is_handled_by_sanctum(): void
    {
        $this->getJson('/api/testing/permission')->assertUnauthorized();
    }

    public function test_authenticated_user_without_permission_receives_safe_forbidden_response(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $roleId = $this->createRole();
        $otherPermissionId = $this->createPermission('dashboard.view');
        $this->attachRole($membership['membership_id'], $roleId);
        $this->grantPermission($roleId, $otherPermissionId, DataScope::ALL->value);

        $response = $this
            ->actingAs($user, 'web')
            ->getJson('/api/testing/permission');

        $response
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Bạn không có quyền thực hiện thao tác này.',
            ]);

        $this->assertStringNotContainsString('users.view', $response->getContent());
        $this->assertStringNotContainsString('data_scope', $response->getContent());
        $this->assertStringNotContainsString('TEST_ROLE', $response->getContent());
    }

    public function test_authenticated_user_with_permission_can_continue(): void
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $roleId = $this->createRole();
        $permissionId = $this->createPermission('users.view');
        $this->attachRole($membership['membership_id'], $roleId);
        $this->grantPermission($roleId, $permissionId, DataScope::ORGANIZATION->value);

        $this
            ->actingAs($user, 'web')
            ->getJson('/api/testing/permission')
            ->assertOk()
            ->assertExactJson(['authorized' => true]);
    }
}
