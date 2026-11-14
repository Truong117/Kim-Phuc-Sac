<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class SpaCatalogTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    private function login(string $role = 'OWNER', string $organization = 'KPS'): User
    {
        $this->seed(AuthorizationSeeder::class);
        $user = User::factory()->create();
        $membership = $this->createMembership($user, organizationCode: $organization);
        $this->attachRole($membership['membership_id'], DB::table('roles')->where('code', $role)->value('id'), true);
        $this->actingAs($user, 'web');
        config(['spa.catalog_enabled' => true]);

        return $user;
    }

    private function product(array $extra = []): array
    {
        return [...['id' => (string) Str::uuid(), 'kind' => 'product', 'name' => 'Kem dưỡng', 'unit' => 'Hũ', 'price' => 150000], ...$extra];
    }

    private function service(array $extra = []): array
    {
        return [...['id' => (string) Str::uuid(), 'kind' => 'service', 'name' => 'Chăm sóc da', 'price' => 250000,
            'duration_minutes' => 60, 'group' => 'Chăm sóc', 'description' => 'Dịch vụ chăm sóc da', 'requires_skin_photo' => true], ...$extra];
    }

    #[DataProvider('managers')]
    public function test_existing_management_roles_save_both_catalog_types_and_retrieve_them(string $role): void
    {
        $user = $this->login($role);
        $product = $this->product();
        $service = $this->service();
        $this->getJson('/api/spa/catalog/status')->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('can_manage', true);
        $this->postJson('/api/spa/catalog', $product)->assertCreated()->assertJsonPath('data.name', 'Kem dưỡng')->assertJsonPath('data.version', 1);
        $this->postJson('/api/spa/catalog', $service)->assertCreated()->assertJsonPath('data.duration_minutes', 60)->assertJsonPath('data.requires_skin_photo', true);
        $this->getJson('/api/spa/catalog?kind=service')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $service['id']);
        $response = $this->getJson('/api/spa/catalog?kind=product')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $product['id']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('spa_catalog_items', ['id' => $product['id'], 'created_by' => $user->id, 'price' => 150000]);
        $this->assertArrayNotHasKey('stock', $response->json('data.0'));
    }

    public static function managers(): array
    {
        return [['OWNER'], ['ADMIN'], ['EXECUTIVE_ASSISTANT']];
    }

    public function test_authentication_membership_and_scoped_permissions_are_enforced(): void
    {
        $this->getJson('/api/spa/catalog/status')->assertUnauthorized();
        $this->postJson('/api/spa/catalog', $this->product())->assertUnauthorized();
        $this->login('OFFICE_STAFF');
        $this->getJson('/api/spa/catalog/status')->assertForbidden();
        $this->postJson('/api/spa/catalog', $this->product())->assertForbidden();
        $user = $this->login('KPS_SPA_MANAGER');
        $this->getJson('/api/spa/catalog/status')->assertOk()->assertJsonPath('can_manage', false);
        $this->getJson('/api/spa/catalog?kind=product')->assertForbidden();
        $this->postJson('/api/spa/catalog', $this->product())->assertForbidden();
        DB::table('organization_memberships')->where('user_id', $user->id)->update(['is_active' => false]);
        $this->getJson('/api/spa/catalog/status')->assertUnauthorized();
    }

    public function test_disabled_catalog_and_missing_migration_do_not_accept_writes(): void
    {
        $this->login();
        config(['spa.catalog_enabled' => false]);
        $this->getJson('/api/spa/catalog/status')->assertOk()->assertJsonPath('enabled', false);
        $this->postJson('/api/spa/catalog', $this->product())->assertConflict();
        $this->assertDatabaseCount('spa_catalog_items', 0);
        config(['spa.catalog_enabled' => true]);
        Schema::drop('spa_catalog_items');
        $this->getJson('/api/spa/catalog?kind=product')->assertConflict()->assertJsonPath('message', 'SPA_CATALOG_MIGRATION_REQUIRED');
    }

    public function test_retry_does_not_duplicate_and_duplicate_names_are_rejected(): void
    {
        $this->login();
        $product = $this->product();
        $this->postJson('/api/spa/catalog', $product)->assertCreated();
        $this->postJson('/api/spa/catalog', $product)->assertCreated()->assertJsonPath('data.id', $product['id']);
        $this->postJson('/api/spa/catalog', [...$product, 'price' => 300000])->assertConflict();
        $this->postJson('/api/spa/catalog', $this->product(['name' => '  KEM   DƯỠNG  ']))->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->assertDatabaseCount('spa_catalog_items', 1);
    }

    public function test_updates_use_versions_and_cannot_change_type_or_another_organization(): void
    {
        $this->login();
        $product = $this->product();
        $this->postJson('/api/spa/catalog', $product)->assertCreated();
        $changes = $product;
        unset($changes['id']);
        $changes['version'] = 1;
        $changes['price'] = 200000;
        $this->patchJson('/api/spa/catalog/'.$product['id'], $changes)->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.price', 200000);
        $this->patchJson('/api/spa/catalog/'.$product['id'], $changes)->assertConflict();
        $changedType = $this->service(['version' => 2]);
        unset($changedType['id']);
        $this->patchJson('/api/spa/catalog/'.$product['id'], $changedType)->assertConflict();
        $now = now();
        $foreign = DB::table('organizations')->insertGetId(['code' => 'OTHER_SPA', 'name' => 'Other', 'type' => 'internal', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('spa_catalog_items')->where('id', $product['id'])->update(['organization_id' => $foreign]);
        $this->getJson('/api/spa/catalog?kind=product')->assertOk()->assertJsonCount(0, 'data');
        $this->patchJson('/api/spa/catalog/'.$product['id'], [...$changes, 'version' => 2])->assertNotFound();
        $this->postJson('/api/spa/catalog', $product)->assertNotFound();
        $this->assertDatabaseHas('spa_catalog_items', ['id' => $product['id'], 'price' => 200000, 'version' => 2]);
    }

    public function test_invalid_inputs_and_client_supplied_scope_or_stock_are_rejected_atomically(): void
    {
        $this->login();
        foreach ([['name' => ' '], ['name' => str_repeat('a', 101)], ['unit' => ' '], ['unit' => str_repeat('a', 31)], ['price' => -1], ['price' => 1.5], ['price' => 1000000001], ['stock' => 10], ['organization_id' => 999], ['created_by' => 999], ['duration_minutes' => 60]] as $invalid) {
            $this->postJson('/api/spa/catalog', $this->product($invalid))->assertUnprocessable();
        }
        foreach ([['duration_minutes' => 20], ['duration_minutes' => 0], ['duration_minutes' => 195], ['unit' => 'Hũ'], ['group' => str_repeat('a', 101)], ['description' => str_repeat('a', 1001)], ['requires_skin_photo' => 'invalid']] as $invalid) {
            $this->postJson('/api/spa/catalog', $this->service($invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('spa_catalog_items', 0);
    }

    public function test_search_and_pagination_do_not_drop_catalog_items(): void
    {
        $this->login();
        for ($i = 1; $i <= 23; $i++) {
            $this->postJson('/api/spa/catalog', $this->product(['name' => sprintf('Sản phẩm %02d', $i)]))->assertCreated();
        }
        $this->getJson('/api/spa/catalog?kind=product')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 23)->assertJsonPath('meta.last_page', 2);
        $this->getJson('/api/spa/catalog?kind=product&page=2')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/spa/catalog?kind=product&search=23')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Sản phẩm 23');
    }
}
