<?php

namespace Tests\Feature;

use App\Models\IntegrationEntry;
use App\Models\User;
use Database\Seeders\AuthorizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class IntegrationManagementTest extends TestCase
{
    use AuthorizationFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->seed(AuthorizationSeeder::class);
    }

    private function actor(string $role = 'ADMIN', string $organizationCode = 'KPS'): User
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user, organizationCode: $organizationCode);
        $this->attachRole($membership['membership_id'], $this->createRole($role));

        return $user;
    }

    private function entry(User $responsible, array $overrides = []): array
    {
        return array_replace([
            'kind' => 'social', 'platform' => 'tiktok', 'name' => 'Kênh KPS',
            'url' => 'https://www.tiktok.com/@kps', 'responsible_user_id' => $responsible->id,
            'note' => '', 'enabled' => true,
        ], $overrides);
    }

    public function test_authenticated_crud_is_shared_by_kps_users_and_never_calls_providers(): void
    {
        $admin = $this->actor();
        $owner = $this->actor('OWNER');
        $this->actingAs($admin)->getJson('/api/integrations')->assertOk()->assertExactJson(['data' => [], 'actions' => ['can_manage' => true]]);
        $create = $this->postJson('/api/integrations', $this->entry($admin))->assertCreated()->assertJsonPath('data.connection_status', 'not_connected')->assertJsonPath('data.version', 1);
        $id = $create->json('data.id');
        $this->actingAs($owner)->getJson('/api/integrations')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/integrations/'.$id)->assertOk()->assertJsonPath('data.owner', $admin->name)->assertJsonMissingPath('data.url_hash')->assertJsonMissingPath('data.organization_id');
        $this->patchJson('/api/integrations/'.$id, $this->entry($owner, ['name' => 'Tên mới', 'version' => 1, 'enabled' => false]))->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.enabled', false);
        $this->deleteJson('/api/integrations/'.$id, ['version' => 2])->assertNoContent();
        $this->getJson('/api/integrations/'.$id)->assertNotFound();
        $this->assertDatabaseCount('integration_entries', 0);
        Http::assertNothingSent();
    }

    public function test_view_only_users_cannot_write_and_staff_cannot_read(): void
    {
        $admin = $this->actor();
        $id = $this->actingAs($admin)->postJson('/api/integrations', $this->entry($admin))->json('data.id');
        $assistant = $this->actor('EXECUTIVE_ASSISTANT');
        $this->actingAs($assistant)->getJson('/api/integrations')->assertOk()->assertJsonPath('actions.can_manage', false);
        $this->postJson('/api/integrations', $this->entry($assistant))->assertForbidden();
        $this->patchJson('/api/integrations/'.$id, $this->entry($assistant, ['version' => 1]))->assertForbidden();
        $this->deleteJson('/api/integrations/'.$id, ['version' => 1])->assertForbidden();
        $this->actingAs($this->actor('OFFICE_STAFF'))->getJson('/api/integrations')->assertForbidden();
        $this->getJson('/api/integrations/references')->assertForbidden();
    }

    public function test_guests_inactive_memberships_and_other_organizations_cannot_access(): void
    {
        $this->getJson('/api/integrations')->assertUnauthorized();
        $admin = $this->actor();
        $other = $this->actor('ADMIN', 'OTHER');
        $this->actingAs($other)->getJson('/api/integrations')->assertUnauthorized();
        DB::table('organization_memberships')->where('user_id', $admin->id)->update(['is_active' => false]);
        $this->actingAs($admin)->getJson('/api/integrations')->assertUnauthorized();
    }

    public function test_foreign_records_are_hidden_and_organization_cannot_be_chosen_by_client(): void
    {
        $admin = $this->actor();
        $other = $this->actor('ADMIN', 'OTHER');
        $organization = DB::table('organizations')->where('code', 'OTHER')->value('id');
        $entry = IntegrationEntry::query()->create([
            'organization_id' => $organization, 'kind' => 'social', 'platform' => 'tiktok',
            'name' => 'Private other org', 'url' => 'https://tiktok.com/@other', 'url_hash' => hash('sha256', 'other'),
            'responsible_user_id' => $other->id, 'enabled' => true,
        ]);
        $this->actingAs($admin)->getJson('/api/integrations')->assertJsonCount(0, 'data');
        $this->getJson('/api/integrations/'.$entry->id)->assertNotFound();
        $this->patchJson('/api/integrations/'.$entry->id, $this->entry($admin, ['version' => 1]))->assertNotFound();
        $this->deleteJson('/api/integrations/'.$entry->id, ['version' => 1])->assertNotFound();
        $this->postJson('/api/integrations', $this->entry($admin, ['organization_id' => $organization]))->assertUnprocessable()->assertJsonValidationErrors('organization_id');
    }

    public function test_responsible_must_be_an_active_kps_member_and_references_exclude_private_fields(): void
    {
        $admin = $this->actor();
        $inactive = $this->actor('OFFICE_STAFF');
        DB::table('organization_memberships')->where('user_id', $inactive->id)->update(['is_active' => false]);
        $other = $this->actor('ADMIN', 'OTHER');
        $this->actingAs($admin)->getJson('/api/integrations/references')->assertOk()->assertJsonCount(1, 'data')->assertExactJson(['data' => [['id' => (string) $admin->id, 'name' => $admin->name]]]);
        foreach ([$inactive->id, $other->id, 999999] as $id) {
            $this->postJson('/api/integrations', $this->entry($admin, ['responsible_user_id' => $id]))->assertUnprocessable()->assertJsonValidationErrors('responsible_user_id');
        }
    }

    public function test_invalid_links_and_duplicates_are_rejected_but_folder_ids_keep_case(): void
    {
        $admin = $this->actor();
        $this->actingAs($admin);
        foreach (['javascript:alert(1)', 'https://tiktok.com.evil.test/@kps', 'https://facebook.com/kps', 'https://tiktok.com/@kps?access_token=private', 'https://user:password@tiktok.com/@kps'] as $url) {
            $this->postJson('/api/integrations', $this->entry($admin, ['url' => $url]))->assertUnprocessable()->assertJsonValidationErrors('url');
        }
        $this->postJson('/api/integrations', $this->entry($admin))->assertCreated();
        $this->postJson('/api/integrations', $this->entry($admin, ['url' => 'https://tiktok.com/@kps/?utm_source=report']))->assertUnprocessable()->assertJsonValidationErrors('url');
        foreach (['AbC', 'abc'] as $folder) {
            $this->postJson('/api/integrations', $this->entry($admin, ['kind' => 'drive', 'platform' => null, 'url' => 'https://drive.google.com/drive/folders/'.$folder]))->assertCreated()->assertJsonPath('data.platform', null);
        }
        $this->postJson('/api/integrations', $this->entry($admin, ['kind' => 'drive', 'platform' => null, 'url' => 'https://drive.google.com/drive/u/0/folders/AbC?usp=sharing']))->assertUnprocessable()->assertJsonValidationErrors('url');
        Http::assertNothingSent();
    }

    public function test_stale_edits_and_deletes_cannot_overwrite_a_newer_version(): void
    {
        $admin = $this->actor();
        $id = $this->actingAs($admin)->postJson('/api/integrations', $this->entry($admin))->json('data.id');
        $this->patchJson('/api/integrations/'.$id, $this->entry($admin, ['version' => 1, 'name' => 'Mới']))->assertOk();
        $this->patchJson('/api/integrations/'.$id, $this->entry($admin, ['version' => 1, 'name' => 'Cũ']))->assertConflict();
        $this->deleteJson('/api/integrations/'.$id, ['version' => 1])->assertConflict();
        $this->assertDatabaseHas('integration_entries', ['id' => $id, 'name' => 'Mới', 'version' => 2]);
        $this->deleteJson('/api/integrations/'.$id)->assertUnprocessable()->assertJsonValidationErrors('version');
    }

    public function test_invalid_input_cannot_modify_readonly_metadata_or_switch_kind(): void
    {
        $admin = $this->actor();
        $this->actingAs($admin)->postJson('/api/integrations', $this->entry($admin, ['name' => '  ', 'enabled' => 'oops', 'connection_status' => 'connected']))->assertUnprocessable()->assertJsonValidationErrors(['name', 'enabled', 'connection_status']);
        $id = $this->postJson('/api/integrations', $this->entry($admin))->json('data.id');
        $this->patchJson('/api/integrations/'.$id, $this->entry($admin, ['version' => 1, 'kind' => 'drive', 'platform' => null, 'url' => 'https://drive.google.com/drive/folders/abc']))->assertUnprocessable()->assertJsonValidationErrors('kind');
    }
}
