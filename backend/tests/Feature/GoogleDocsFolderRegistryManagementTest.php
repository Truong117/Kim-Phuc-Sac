<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\User;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDriveFileMetadataClient;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDriveApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class GoogleDocsFolderRegistryManagementTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    private const FOLDER_A = 'ApprovedFolder_1234567890_AAAAA';

    private const FOLDER_B = 'ApprovedFolder_1234567890_BBBBB';

    private RegistryGoogleDriveFileMetadataClient $drive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->drive = new RegistryGoogleDriveFileMetadataClient;
        $this->app->instance(GoogleDriveFileMetadataClient::class, $this->drive);
    }

    public function test_authorized_admin_approves_replaces_and_disables_with_append_only_audit(): void
    {
        [$admin] = $this->userWithPermissions(['weekly_plans.google_docs.configure']);

        $this->actingAs($admin)->putJson('/api/weekly-plans/google-docs/folder', [
            'google_folder_id' => self::FOLDER_A,
        ])->assertOk()
            ->assertJsonPath('data.google_folder_id', self::FOLDER_A)
            ->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('organization_google_docs_folder_events', [
            'actor_user_id' => $admin->id,
            'event_type' => 'FOLDER_APPROVED',
            'previous_google_folder_id' => null,
            'current_google_folder_id' => self::FOLDER_A,
        ]);

        $this->actingAs($admin)->putJson('/api/weekly-plans/google-docs/folder', [
            'google_folder_id' => self::FOLDER_A,
        ])->assertOk();
        $this->assertDatabaseCount('organization_google_docs_folder_events', 1);

        $this->actingAs($admin)->putJson('/api/weekly-plans/google-docs/folder', [
            'google_folder_id' => self::FOLDER_B,
        ])->assertOk()
            ->assertJsonPath('data.google_folder_id', self::FOLDER_B);
        $this->assertDatabaseHas('organization_google_docs_folder_events', [
            'actor_user_id' => $admin->id,
            'event_type' => 'FOLDER_REPLACED',
            'previous_google_folder_id' => self::FOLDER_A,
            'current_google_folder_id' => self::FOLDER_B,
        ]);

        $this->actingAs($admin)->deleteJson('/api/weekly-plans/google-docs/folder')
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('organization_google_docs_folder_events', [
            'actor_user_id' => $admin->id,
            'event_type' => 'FOLDER_DISABLED',
            'previous_google_folder_id' => self::FOLDER_B,
            'current_google_folder_id' => null,
        ]);

        $this->actingAs($admin)->deleteJson('/api/weekly-plans/google-docs/folder')->assertOk();
        $this->assertDatabaseCount('organization_google_docs_folder_events', 3);
        $this->assertDatabaseCount('organization_google_docs_folders', 1);
        $this->assertSame(3, $this->drive->calls);
    }

    public function test_hr_cannot_manage_registry_and_client_cannot_select_an_organization(): void
    {
        [$hr] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        [$admin] = $this->userWithPermissions(['weekly_plans.google_docs.configure']);

        $this->actingAs($hr)->putJson('/api/weekly-plans/google-docs/folder', [
            'google_folder_id' => self::FOLDER_A,
        ])->assertForbidden();

        $this->actingAs($admin)->putJson('/api/weekly-plans/google-docs/folder', [
            'google_folder_id' => self::FOLDER_A,
            'organization_id' => 999999,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('organization_google_docs_folders', 0);
    }

    public function test_inactive_membership_cannot_manage_registry(): void
    {
        [$admin] = $this->userWithPermissions(
            ['weekly_plans.google_docs.configure'],
            active: false,
        );

        $this->actingAs($admin)->putJson('/api/weekly-plans/google-docs/folder', [
            'google_folder_id' => self::FOLDER_A,
        ])->assertUnauthorized();
        $this->assertDatabaseCount('organization_google_docs_folders', 0);
    }

    public function test_same_folder_cannot_be_assigned_to_a_second_organization(): void
    {
        [$firstAdmin, , $firstOrganizationId] = $this->userWithPermissions([
            'weekly_plans.google_docs.configure',
        ]);
        $this->actingAs($firstAdmin)->putJson('/api/weekly-plans/google-docs/folder', [
            'google_folder_id' => self::FOLDER_A,
        ])->assertOk();

        DB::table('organizations')->where('id', $firstOrganizationId)->update([
            'code' => 'KPS_ARCHIVED_TEST',
            'updated_at' => now(),
        ]);
        DB::table('organizations')->insert([
            'code' => 'KPS',
            'name' => 'KPS second test organization',
            'type' => 'internal',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        [$secondAdmin] = $this->userWithPermissions(['weekly_plans.google_docs.configure']);

        $this->actingAs($secondAdmin)->putJson('/api/weekly-plans/google-docs/folder', [
            'google_folder_id' => self::FOLDER_A,
        ])->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_FOLDER_ALREADY_ASSIGNED');

        $this->assertDatabaseCount('organization_google_docs_folders', 1);
        $this->assertDatabaseCount('organization_google_docs_folder_events', 1);
    }

    public function test_non_folder_or_mismatched_metadata_is_rejected_before_registry_write(): void
    {
        [$admin] = $this->userWithPermissions(['weekly_plans.google_docs.configure']);

        $this->drive->mimeType = 'application/vnd.google-apps.document';
        $response = $this->actingAs($admin)->putJson('/api/weekly-plans/google-docs/folder', [
            'google_folder_id' => self::FOLDER_A,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'GOOGLE_DOCS_FOLDER_INVALID');
        $this->assertStringNotContainsString(self::FOLDER_A, $response->getContent());

        $this->drive->mimeType = RegistryGoogleDriveFileMetadataClient::FOLDER_MIME_TYPE;
        $this->drive->responseId = self::FOLDER_B;
        $this->actingAs($admin)->putJson('/api/weekly-plans/google-docs/folder', [
            'google_folder_id' => self::FOLDER_A,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'GOOGLE_DOCS_FOLDER_INVALID');

        $this->assertDatabaseCount('organization_google_docs_folders', 0);
        $this->assertDatabaseCount('organization_google_docs_folder_events', 0);
    }

    public function test_google_or_credential_failure_is_safe_and_never_writes_registry(): void
    {
        [$admin] = $this->userWithPermissions(['weekly_plans.google_docs.configure']);

        foreach ([
            [404, null, 422, 'GOOGLE_DOCS_FOLDER_INVALID'],
            [401, null, 502, 'GOOGLE_DOCS_FOLDER_ACCESS_DENIED'],
            [403, null, 502, 'GOOGLE_DOCS_FOLDER_ACCESS_DENIED'],
            [429, null, 429, 'GOOGLE_DOCS_FOLDER_RATE_LIMITED'],
            [500, null, 503, 'GOOGLE_DOCS_FOLDER_VERIFICATION_UNAVAILABLE'],
            [0, 'CONFIGURATION', 503, 'GOOGLE_DOCS_FOLDER_VERIFICATION_UNAVAILABLE'],
        ] as [$providerStatus, $reason, $expectedStatus, $expectedCode]) {
            $this->drive->error = new GoogleDriveApiException($providerStatus, $reason);

            $response = $this->actingAs($admin)->putJson('/api/weekly-plans/google-docs/folder', [
                'google_folder_id' => self::FOLDER_A,
            ])->assertStatus($expectedStatus)
                ->assertJsonPath('code', $expectedCode);
            $this->assertStringNotContainsString(self::FOLDER_A, $response->getContent());
            $this->assertDatabaseCount('organization_google_docs_folders', 0);
            $this->assertDatabaseCount('organization_google_docs_folder_events', 0);
        }
    }

    /** @return array{User, int, int} */
    private function userWithPermissions(array $permissions, bool $active = true): array
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user, $active);
        $roleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $roleId, true);

        foreach ($permissions as $permission) {
            $this->grantPermission(
                $roleId,
                $this->createPermission($permission),
                DataScope::ORGANIZATION->value,
            );
        }

        return [$user, $membership['membership_id'], $membership['organization_id']];
    }
}

final class RegistryGoogleDriveFileMetadataClient implements GoogleDriveFileMetadataClient
{
    public const FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';

    public int $calls = 0;

    public string $mimeType = self::FOLDER_MIME_TYPE;

    public ?string $responseId = null;

    public ?GoogleDriveApiException $error = null;

    public function getFileMetadata(
        string $fileId,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array {
        $this->calls++;

        if ($this->error instanceof GoogleDriveApiException) {
            throw $this->error;
        }

        return [
            'id' => $this->responseId ?? $fileId,
            'mime_type' => $this->mimeType,
            'parents' => [],
        ];
    }
}
