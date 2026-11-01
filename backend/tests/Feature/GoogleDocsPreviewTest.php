<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Models\Organization;
use App\Models\OrganizationGoogleDocsFolder;
use App\Models\User;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDocsApiClient;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDriveFileMetadataClient;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsApiException;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDriveApiException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class GoogleDocsPreviewTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    private const DOCUMENT_ID = 'KpsGoogleDocument_1234567890AbCdEfGhIj';

    private const APPROVED_FOLDER = 'KpsApprovedFolder_A_1234567890AbCdEf';

    private const OTHER_FOLDER = 'KpsApprovedFolder_B_1234567890AbCdEf';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('google_docs.preview_per_minute', 100);
    }

    public function test_authorized_hr_receives_only_safe_structured_preview_without_mutating_plans_or_tasks(): void
    {
        [$hr, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $this->approveFolder($organizationId, $hr);
        $drive = new PreviewSequenceGoogleDriveClient([$this->metadata(), $this->metadata()]);
        $docs = new PreviewGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);

        $response = $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/preview', [
            'document_url' => $this->documentUrl(),
        ])->assertOk()
            ->assertJsonPath('data.title', 'Kế hoạch tuần giả lập')
            ->assertJsonPath('data.revision_id', 'revision-2026-10-26')
            ->assertJsonPath('data.identity_verified', false)
            ->assertJsonPath('data.tab_count', 2)
            ->assertJsonPath('data.tabs.0.tab_id', 'tab-root')
            ->assertJsonPath('data.tabs.0.source_group.identity_verified', false)
            ->assertJsonPath('data.tabs.1.parent_tab_id', 'tab-root');

        $payload = $response->json('data');
        $this->assertIsArray($payload);
        $this->assertArrayNotHasKey('document_id', $payload);
        $this->assertStringNotContainsString('Nội dung nhạy cảm', $response->getContent());
        $this->assertStringNotContainsString(self::DOCUMENT_ID, $response->getContent());
        $this->assertStringNotContainsString('employee_id', $response->getContent());
        $this->assertStringNotContainsString('assignee', $response->getContent());
        $this->assertTrue($docs->lastIncludeTabsContent);
        $this->assertSame(2, $drive->calls);
        $this->assertDatabaseCount('weekly_plans', 0);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_unauthorized_or_inactive_user_is_rejected_before_google_is_called(): void
    {
        [$unauthorized] = $this->userWithPermissions([]);
        [$inactive] = $this->userWithPermissions(['weekly_plans.manage_draft'], active: false);
        $drive = new PreviewSequenceGoogleDriveClient([$this->metadata()]);
        $docs = new PreviewGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);

        $this->actingAs($unauthorized)->postJson('/api/weekly-plans/google-docs/preview', [
            'document_url' => $this->documentUrl(),
        ])->assertForbidden();
        $this->actingAs($inactive)->postJson('/api/weekly-plans/google-docs/preview', [
            'document_url' => $this->documentUrl(),
        ])->assertUnauthorized();

        $this->assertSame(0, $drive->calls);
        $this->assertSame(0, $docs->calls);
    }

    public function test_missing_inactive_or_cross_organization_registry_fails_closed_without_metadata_leak(): void
    {
        [$hr, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $drive = new PreviewSequenceGoogleDriveClient([$this->metadata()]);
        $docs = new PreviewGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);

        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/preview', [
            'document_url' => $this->documentUrl(),
        ])->assertNotFound()
            ->assertJsonPath('code', 'GOOGLE_DOCS_NOT_AVAILABLE')
            ->assertJsonMissing(['google_folder_id' => self::APPROVED_FOLDER]);

        $folder = $this->approveFolder($organizationId, $hr, active: false);
        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/preview', [
            'document_url' => $this->documentUrl(),
        ])->assertNotFound();
        $folder->delete();

        $otherOrganization = Organization::query()->create([
            'code' => 'OTHER_GOOGLE',
            'name' => 'Other Google Organization',
            'type' => 'internal',
            'is_active' => true,
        ]);
        $this->approveFolder($otherOrganization->id, $hr);
        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/preview', [
            'document_url' => $this->documentUrl(),
        ])->assertNotFound()
            ->assertJsonMissing(['google_folder_id' => self::APPROVED_FOLDER]);

        $this->assertSame(0, $drive->calls);
        $this->assertSame(0, $docs->calls);
    }

    public function test_client_cannot_select_an_organization_or_supply_a_document_id(): void
    {
        [$hr, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $this->approveFolder($organizationId, $hr);
        $drive = new PreviewSequenceGoogleDriveClient([$this->metadata()]);
        $docs = new PreviewGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);

        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/preview', [
            'document_url' => $this->documentUrl(),
            'document_id' => self::DOCUMENT_ID,
            'organization_id' => 999999,
        ])->assertUnprocessable();

        $this->assertSame(0, $drive->calls);
        $this->assertSame(0, $docs->calls);
    }

    public function test_non_canonical_or_non_string_document_url_is_rejected_before_google_is_called(): void
    {
        [$hr, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $this->approveFolder($organizationId, $hr);
        $drive = new PreviewSequenceGoogleDriveClient([$this->metadata()]);
        $docs = new PreviewGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);

        foreach ([
            'https://example.test/document/d/'.self::DOCUMENT_ID,
            ['unexpected-array'],
        ] as $invalidUrl) {
            $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/preview', [
                'document_url' => $invalidUrl,
            ])->assertUnprocessable();
        }

        $this->assertSame(0, $drive->calls);
        $this->assertSame(0, $docs->calls);
    }

    public function test_wrong_mime_shortcut_nested_folder_and_outside_folder_are_rejected(): void
    {
        [$hr, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $this->approveFolder($organizationId, $hr);

        foreach ([
            $this->metadata(mimeType: 'application/pdf'),
            $this->metadata(mimeType: 'application/vnd.google-apps.shortcut'),
            $this->metadata(parents: [self::OTHER_FOLDER]),
            $this->metadata(parents: []),
        ] as $metadata) {
            $drive = new PreviewSequenceGoogleDriveClient([$metadata]);
            $docs = new PreviewGoogleDocsClient($this->document());
            $this->bindGoogleFakes($drive, $docs);

            $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/preview', [
                'document_url' => $this->documentUrl(),
            ])->assertNotFound()
                ->assertJsonPath('code', 'GOOGLE_DOCS_NOT_AVAILABLE');
            $this->assertSame(0, $docs->calls);
        }
    }

    public function test_document_moved_between_precheck_and_postcheck_is_rejected(): void
    {
        [$hr, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $this->approveFolder($organizationId, $hr);
        $drive = new PreviewSequenceGoogleDriveClient([
            $this->metadata(),
            $this->metadata(parents: [self::OTHER_FOLDER]),
        ]);
        $this->bindGoogleFakes($drive, new PreviewGoogleDocsClient($this->document()));

        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/preview', [
            'document_url' => $this->documentUrl(),
        ])->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_AUTHORIZATION_CHANGED');

        $this->assertDatabaseCount('weekly_plans', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_provider_failures_are_mapped_to_safe_statuses(): void
    {
        [$hr, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $this->approveFolder($organizationId, $hr);

        foreach ([
            [401, null, 502, 'GOOGLE_DOCS_PROVIDER_ACCESS_DENIED'],
            [403, null, 502, 'GOOGLE_DOCS_PROVIDER_ACCESS_DENIED'],
            [404, null, 404, 'GOOGLE_DOCS_NOT_AVAILABLE'],
            [429, null, 429, 'GOOGLE_DOCS_RATE_LIMITED'],
            [500, null, 503, 'GOOGLE_DOCS_PROVIDER_UNAVAILABLE'],
            [0, 'CONFIGURATION', 503, 'GOOGLE_DOCS_PROVIDER_UNAVAILABLE'],
        ] as [$providerStatus, $reason, $expectedStatus, $expectedCode]) {
            $drive = new PreviewSequenceGoogleDriveClient(
                errors: [new GoogleDriveApiException($providerStatus, $reason)],
            );
            $this->bindGoogleFakes($drive, new PreviewGoogleDocsClient($this->document()));

            $response = $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/preview', [
                'document_url' => $this->documentUrl(),
            ])->assertStatus($expectedStatus)
                ->assertJsonPath('code', $expectedCode);
            $this->assertStringNotContainsString(self::DOCUMENT_ID, $response->getContent());
            $this->assertStringNotContainsString(self::APPROVED_FOLDER, $response->getContent());
        }
    }

    public function test_oversized_document_response_is_rejected_safely(): void
    {
        [$hr, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $this->approveFolder($organizationId, $hr);
        $this->bindGoogleFakes(
            new PreviewSequenceGoogleDriveClient([$this->metadata()]),
            new PreviewGoogleDocsClient(error: new GoogleDocsApiException(0, 'RESPONSE_TOO_LARGE')),
        );

        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/preview', [
            'document_url' => $this->documentUrl(),
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'GOOGLE_DOCS_LIMIT_EXCEEDED');
    }

    public function test_preview_is_throttled_by_user_and_organization(): void
    {
        config()->set('google_docs.preview_per_minute', 2);
        [$hr, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $this->approveFolder($organizationId, $hr);
        $this->bindGoogleFakes(
            new PreviewSequenceGoogleDriveClient([$this->metadata()]),
            new PreviewGoogleDocsClient($this->document()),
        );
        RateLimiter::clear("google-docs-preview:{$organizationId}:{$hr->id}");

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/preview', [
                'document_url' => $this->documentUrl(),
            ])->assertOk();
        }

        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/preview', [
            'document_url' => $this->documentUrl(),
        ])->assertTooManyRequests()
            ->assertJsonPath('code', 'GOOGLE_DOCS_PREVIEW_RATE_LIMITED');
    }

    /** @return array{User, int} */
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

        return [$user, $membership['organization_id']];
    }

    private function approveFolder(
        int $organizationId,
        User $actor,
        bool $active = true,
    ): OrganizationGoogleDocsFolder {
        return OrganizationGoogleDocsFolder::query()->create([
            'organization_id' => $organizationId,
            'google_folder_id' => self::APPROVED_FOLDER,
            'is_active' => $active,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
        ]);
    }

    private function bindGoogleFakes(
        PreviewSequenceGoogleDriveClient $drive,
        PreviewGoogleDocsClient $docs,
    ): void {
        $this->app->instance(GoogleDriveFileMetadataClient::class, $drive);
        $this->app->instance(GoogleDocsApiClient::class, $docs);
    }

    private function documentUrl(): string
    {
        return 'https://docs.google.com/document/d/'.self::DOCUMENT_ID.'/edit';
    }

    /** @param list<string>|null $parents
     * @return array{id: string, mime_type: string, parents: list<string>}
     */
    private function metadata(
        ?array $parents = null,
        string $mimeType = 'application/vnd.google-apps.document',
    ): array {
        return [
            'id' => self::DOCUMENT_ID,
            'mime_type' => $mimeType,
            'parents' => $parents ?? [self::APPROVED_FOLDER],
        ];
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return [
            'documentId' => self::DOCUMENT_ID,
            'title' => 'Kế hoạch tuần giả lập',
            'revisionId' => 'revision-2026-10-26',
            'tabs' => [
                $this->tab('tab-root', 'Phương Anh', 0, 'Nội dung nhạy cảm', [
                    $this->tab('tab-child', 'Huyền My', 0, 'Đầu việc con', [], 'tab-root'),
                ]),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function tab(
        string $id,
        string $title,
        int $index,
        string $text,
        array $children = [],
        ?string $parentId = null,
    ): array {
        $properties = ['tabId' => $id, 'title' => $title, 'index' => $index];
        if ($parentId !== null) {
            $properties['parentTabId'] = $parentId;
        }

        return [
            'tabProperties' => $properties,
            'documentTab' => [
                'body' => [
                    'content' => [[
                        'paragraph' => [
                            'elements' => [['textRun' => ['content' => $text."\n"]]],
                        ],
                    ]],
                ],
            ],
            'childTabs' => $children,
        ];
    }
}

final class PreviewSequenceGoogleDriveClient implements GoogleDriveFileMetadataClient
{
    public int $calls = 0;

    /**
     * @param  list<array{id: string, mime_type: string, parents: list<string>}>  $responses
     * @param  list<GoogleDriveApiException>  $errors
     */
    public function __construct(
        private readonly array $responses = [],
        private readonly array $errors = [],
    ) {}

    public function getFileMetadata(
        string $fileId,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array {
        $index = $this->calls++;
        if (isset($this->errors[$index])) {
            throw $this->errors[$index];
        }

        return $this->responses[$index]
            ?? $this->responses[array_key_last($this->responses)]
            ?? [];
    }
}

final class PreviewGoogleDocsClient implements GoogleDocsApiClient
{
    public int $calls = 0;

    public ?bool $lastIncludeTabsContent = null;

    /** @param array<string, mixed> $document */
    public function __construct(
        private readonly array $document = [],
        private readonly ?GoogleDocsApiException $error = null,
    ) {}

    public function getDocument(
        string $documentId,
        bool $includeTabsContent,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array {
        $this->calls++;
        $this->lastIncludeTabsContent = $includeTabsContent;
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->document;
    }
}
