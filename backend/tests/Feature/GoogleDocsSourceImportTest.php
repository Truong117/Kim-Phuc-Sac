<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\WeeklyPlanSourceType;
use App\Enums\WeeklyPlanStatus;
use App\Enums\WeeklyPlanWorkflowVersion;
use App\Http\Resources\WeeklyPlanResource;
use App\Models\Organization;
use App\Models\OrganizationGoogleDocsFolder;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanGoogleDocsSource;
use App\Models\WeeklyPlanSourcePassage;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDocsApiClient;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDriveFileMetadataClient;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class GoogleDocsSourceImportTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    private const DOCUMENT_ID = 'KpsDurableDocument_1234567890AbCdEfGhIj';

    private const APPROVED_FOLDER = 'KpsDurableFolder_1234567890AbCdEfGhIj';

    private const REVISION = 'revision-durable-1';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-27 08:00:00', 'Asia/Ho_Chi_Minh');
        config()->set('google_docs.preview_per_minute', 100);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_hr_confirms_a_fresh_snapshot_and_persists_an_encrypted_durable_source(): void
    {
        Log::spy();
        [$hr, $organizationId] = $this->userWithManageDraft();
        $folder = $this->approveFolder($organizationId, $hr);
        $drive = new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]);
        $docs = new SourceGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);
        $requestId = 'b9ab619b-9084-40e6-8d11-38bbef97f58e';

        $response = $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', [
            'document_url' => $this->documentUrl(),
            'expected_snapshot_sha256' => $this->snapshotSha256(),
            'request_id' => $requestId,
        ])->assertCreated()
            ->assertJsonPath('data.source_type', 'GOOGLE_DOCS')
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.source_file.availability', 'NOT_APPLICABLE')
            ->assertJsonPath('data.source_document.status', 'EXTRACTED')
            ->assertJsonPath('data.source_document.google_docs.title', 'Kế hoạch tuần giả lập')
            ->assertJsonPath('data.source_document.google_docs.identity_verified', false)
            ->assertJsonPath('data.source_document.google_docs.tab_count', 3)
            ->assertJsonPath('data.source_document.google_docs.passage_count', 2)
            ->assertJsonPath('data.source_document.google_docs.tabs.1.title', 'Tên trùng')
            ->assertJsonPath('data.source_document.google_docs.tabs.2.title', 'Tên trùng')
            ->assertJsonPath('data.source_document.google_docs.tabs.1.parent_tab_id', 'tab-root')
            ->assertJsonPath('data.actions.can_ai_extract_source', false)
            ->assertJsonCount(2, 'data.source_document.passages')
            ->assertJsonCount(0, 'data.entries')
            ->assertJsonMissingPath('data.source_document.google_docs.revision_id');

        $this->assertSame(2, $drive->calls);
        $this->assertSame(1, $docs->calls);
        $this->assertTrue($docs->lastIncludeTabsContent);
        $this->assertDatabaseCount('weekly_plans', 1);
        $this->assertDatabaseCount('weekly_plan_google_docs_sources', 1);
        $this->assertDatabaseCount('weekly_plan_source_passages', 2);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseHas('weekly_plan_google_docs_sources', [
            'organization_id' => $organizationId,
            'approved_folder_id' => $folder->id,
            'confirmed_by_user_id' => $hr->id,
            'request_id' => $requestId,
            'snapshot_sha256' => $this->snapshotSha256(),
            'passage_count' => 2,
        ]);
        $this->assertDatabaseHas('weekly_plan_events', [
            'event_type' => 'GOOGLE_DOCS_SOURCE_CONFIRMED',
        ]);
        $this->assertDatabaseHas('weekly_plan_events', [
            'event_type' => 'SOURCE_TEXT_EXTRACTED',
        ]);

        $passages = WeeklyPlanSourcePassage::query()->orderBy('sequence')->get();
        $this->assertSame([
            'gdoc:'.substr($this->snapshotSha256(), 0, 16).':'
                .substr(hash('sha256', 'tab-child'), 0, 16).':1',
            'gdoc:'.substr($this->snapshotSha256(), 0, 16).':'
                .substr(hash('sha256', 'tab-second'), 0, 16).':2',
        ], $passages->pluck('source_key')->all());
        $this->assertNotSame(
            $passages[0]->metadata['source_group']['key'],
            $passages[1]->metadata['source_group']['key'],
        );
        $this->assertFalse($passages[0]->metadata['source_group']['identity_verified']);
        $this->assertFalse($passages[1]->metadata['source_group']['identity_verified']);

        $source = WeeklyPlanGoogleDocsSource::query()->firstOrFail();
        $this->assertSame(self::DOCUMENT_ID, $source->google_document_id);
        $this->assertSame('Kế hoạch tuần giả lập', $source->document_title);
        $this->assertSame(self::REVISION, $source->revision_id);
        $this->assertSame('tab-root', $source->tab_manifest[0]['tab_id']);

        $raw = DB::table('weekly_plan_google_docs_sources')->first();
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(self::DOCUMENT_ID, (string) $raw->google_document_id);
        $this->assertStringNotContainsString('Kế hoạch tuần giả lập', (string) $raw->document_title);
        $this->assertStringNotContainsString('tab-root', (string) $raw->tab_manifest);
        $this->assertStringNotContainsString(self::DOCUMENT_ID, $response->getContent());
        $this->assertStringNotContainsString(self::APPROVED_FOLDER, $response->getContent());
        $this->assertStringNotContainsString('approved_folder_registry_id', $response->getContent());
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
    }

    public function test_google_docs_creation_explicitly_persists_v3_and_binds_version_to_idempotency(): void
    {
        config()->set('weekly_plans.workflow_v3_enabled', true);
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $this->bindGoogleFakes(
            new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]),
            new SourceGoogleDocsClient($this->document()),
        );
        $requestId = 'c1d777b8-2bbf-4e71-a436-f8b68934bf30';
        $payload = $this->validPayload($requestId) + ['workflow_version' => 3];

        $response = $this->actingAs($hr)
            ->postJson('/api/weekly-plans/google-docs/import', $payload)
            ->assertCreated()
            ->assertJsonPath('data.workflow_version', 3)
            ->assertJsonPath('data.status', 'DRAFT');

        $plan = WeeklyPlan::query()->findOrFail((int) $response->json('data.id'));
        $this->assertSame(WeeklyPlanWorkflowVersion::V3, $plan->workflow_version);

        $this->actingAs($hr)->postJson(
            '/api/weekly-plans/google-docs/import',
            $this->validPayload($requestId) + ['workflow_version' => 2],
        )->assertConflict()->assertJsonPath('code', 'GOOGLE_DOCS_REQUEST_CONFLICT');
        $this->assertDatabaseCount('weekly_plans', 1);
    }

    public function test_google_source_ai_extraction_requires_completed_mappings_before_the_gateway(): void
    {
        Http::preventStrayRequests();
        config()->set([
            'weekly_plans.document_ai_driver' => 'openai',
            'kps_ai.api_key' => 'test-key-that-must-not-be-used',
            'kps_ai.base_url' => 'https://api.openai.test/v1',
        ]);
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $this->bindGoogleFakes(
            new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]),
            new SourceGoogleDocsClient($this->document()),
        );
        $planId = $this->actingAs($hr)
            ->postJson('/api/weekly-plans/google-docs/import', $this->validPayload())
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$planId}/source/ai-extract")
            ->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_MAPPING_INCOMPLETE');

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_budget_periods', 0);
        $this->assertDatabaseCount('ai_usage_records', 0);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_snapshot_mismatch_fails_without_any_database_write(): void
    {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $this->bindGoogleFakes(
            new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]),
            new SourceGoogleDocsClient($this->document()),
        );

        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', [
            'document_url' => $this->documentUrl(),
            'expected_snapshot_sha256' => str_repeat('a', 64),
            'request_id' => '89f778d1-87be-4197-a49d-41495805130a',
        ])->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_SNAPSHOT_CHANGED');

        $this->assertDatabaseCount('weekly_plans', 0);
        $this->assertDatabaseCount('weekly_plan_google_docs_sources', 0);
        $this->assertDatabaseCount('weekly_plan_source_passages', 0);
    }

    public function test_same_request_is_idempotent_without_calling_google_again(): void
    {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $drive = new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]);
        $docs = new SourceGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);
        $payload = $this->validPayload('d75cfd4c-ac72-43b0-8060-6e489e0f62a0');

        $first = $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', $payload)
            ->assertCreated();
        $second = $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', $payload)
            ->assertCreated();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(2, $drive->calls);
        $this->assertSame(1, $docs->calls);
        $this->assertDatabaseCount('weekly_plans', 1);
        $this->assertDatabaseCount('weekly_plan_google_docs_sources', 1);
        $this->assertDatabaseCount('weekly_plan_source_passages', 2);
    }

    public function test_replay_after_folder_disable_or_replace_returns_existing_plan_without_google_or_writes(): void
    {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $folder = $this->approveFolder($organizationId, $hr);
        $drive = new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]);
        $docs = new SourceGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);
        $payload = $this->validPayload('24dbfc86-f8cb-4876-935a-71757156043b');
        $planId = $this->actingAs($hr)
            ->postJson('/api/weekly-plans/google-docs/import', $payload)
            ->assertCreated()
            ->json('data.id');

        $folder->update(['is_active' => false]);
        $this->actingAs($hr)
            ->postJson('/api/weekly-plans/google-docs/import', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $planId);

        $folder->update([
            'is_active' => true,
            'google_folder_id' => 'ReplacementFolder_1234567890AbCdEfGhIj',
        ]);
        $this->actingAs($hr)
            ->postJson('/api/weekly-plans/google-docs/import', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $planId);

        $this->assertSame(2, $drive->calls);
        $this->assertSame(1, $docs->calls);
        $this->assertDatabaseCount('weekly_plans', 1);
        $this->assertDatabaseCount('weekly_plan_google_docs_sources', 1);
        $this->assertDatabaseCount('weekly_plan_source_passages', 2);
        $this->assertDatabaseCount('weekly_plan_events', 2);
    }

    public function test_replay_requires_current_permission_and_active_membership(): void
    {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $drive = new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]);
        $docs = new SourceGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);
        $payload = $this->validPayload('81bf2392-fb03-4a45-84fe-b09ce4ca10cb');
        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', $payload)
            ->assertCreated();

        $this->revokeManageDraft($hr);
        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', $payload)
            ->assertForbidden();

        $this->grantManageDraftToCurrentRole($hr);
        $this->deactivateMembership($hr);
        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', $payload)
            ->assertUnauthorized();

        $this->assertSame(2, $drive->calls);
        $this->assertSame(1, $docs->calls);
        $this->assertDatabaseCount('weekly_plans', 1);
        $this->assertDatabaseCount('weekly_plan_google_docs_sources', 1);
    }

    public function test_same_organization_authorized_user_can_replay_but_cross_organization_user_cannot(): void
    {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $drive = new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]);
        $docs = new SourceGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);
        $payload = $this->validPayload('6591e15f-b4c9-47a7-8005-136098006eb8');
        $planId = $this->actingAs($hr)
            ->postJson('/api/weekly-plans/google-docs/import', $payload)
            ->assertCreated()
            ->json('data.id');

        [$sameOrganizationUser] = $this->userWithManageDraft();
        $this->actingAs($sameOrganizationUser)
            ->postJson('/api/weekly-plans/google-docs/import', $payload)
            ->assertCreated()
            ->assertJsonPath('data.id', $planId);

        $otherOrganizationUser = User::factory()->create();
        $this->createMembership(
            $otherOrganizationUser,
            organizationCode: 'OTHER_GOOGLE_REPLAY',
        );
        $this->actingAs($otherOrganizationUser)
            ->postJson('/api/weekly-plans/google-docs/import', $payload)
            ->assertUnauthorized();

        $this->assertSame(2, $drive->calls);
        $this->assertSame(1, $docs->calls);
        $this->assertDatabaseCount('weekly_plans', 1);
        $this->assertDatabaseCount('weekly_plan_google_docs_sources', 1);
    }

    public function test_reusing_request_id_for_a_different_snapshot_is_rejected(): void
    {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $this->bindGoogleFakes(
            new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]),
            new SourceGoogleDocsClient($this->document()),
        );
        $requestId = '9ee923a2-beb7-4f43-a458-1fd76c2e9ce0';
        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', $this->validPayload($requestId))
            ->assertCreated();

        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', [
            'document_url' => $this->documentUrl(),
            'expected_snapshot_sha256' => str_repeat('b', 64),
            'request_id' => $requestId,
        ])->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_REQUEST_CONFLICT');

        $this->assertDatabaseCount('weekly_plans', 1);
    }

    public function test_concurrent_plan_creation_wins_safely_without_partial_google_source_writes(): void
    {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $competingPlan = null;
        $docs = new SourceGoogleDocsClient(
            $this->document(),
            function () use (&$competingPlan, $organizationId, $hr): void {
                $competingPlan = $this->existingPlan(
                    $organizationId,
                    $hr,
                    WeeklyPlanStatus::DRAFT,
                );
            },
        );
        $this->bindGoogleFakes(
            new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]),
            $docs,
        );

        $this->actingAs($hr)
            ->postJson('/api/weekly-plans/google-docs/import', $this->validPayload())
            ->assertConflict()
            ->assertJsonPath('code', 'WEEKLY_PLAN_ALREADY_EXISTS');

        $this->assertNotNull($competingPlan);
        $this->assertDatabaseCount('weekly_plans', 1);
        $this->assertDatabaseCount('weekly_plan_google_docs_sources', 0);
        $this->assertDatabaseCount('weekly_plan_source_passages', 0);
        $this->assertDatabaseCount('weekly_plan_events', 0);
    }

    public function test_concurrent_same_request_returns_the_committed_plan_without_duplicates(): void
    {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $folder = $this->approveFolder($organizationId, $hr);
        $requestId = '39cc464e-82f2-468e-a034-2d6f405758f3';
        $concurrentPlan = null;
        $docs = new SourceGoogleDocsClient(
            $this->document(),
            function () use (
                &$concurrentPlan,
                $folder,
                $hr,
                $organizationId,
                $requestId,
            ): void {
                $concurrentPlan = $this->existingPlan(
                    $organizationId,
                    $hr,
                    WeeklyPlanStatus::DRAFT,
                );
                $concurrentPlan->update([
                    'source_type' => WeeklyPlanSourceType::GOOGLE_DOCS,
                    'source_disk' => null,
                    'source_path' => null,
                    'original_filename' => 'Google Docs',
                    'source_extension' => 'gdoc',
                    'source_mime' => 'application/vnd.google-apps.document',
                    'source_size' => 0,
                    'source_sha256' => $this->snapshotSha256(),
                ]);
                $concurrentPlan->googleDocsSource()->create([
                    'organization_id' => $organizationId,
                    'approved_folder_id' => $folder->id,
                    'confirmed_by_user_id' => $hr->id,
                    'request_id' => $requestId,
                    'request_payload_sha256' => hash(
                        'sha256',
                        self::DOCUMENT_ID.'|'.$this->snapshotSha256(),
                    ),
                    'google_document_id' => self::DOCUMENT_ID,
                    'document_id_sha256' => hash('sha256', self::DOCUMENT_ID),
                    'approved_folder_id_sha256' => hash('sha256', self::APPROVED_FOLDER),
                    'document_title' => 'Kế hoạch concurrent',
                    'revision_id' => self::REVISION,
                    'snapshot_sha256' => $this->snapshotSha256(),
                    'tab_manifest' => [],
                    'passage_count' => 0,
                    'character_count' => 0,
                    'captured_at' => now(),
                    'last_verified_at' => now(),
                    'stale_at' => null,
                ]);
            },
        );
        $drive = new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]);
        $this->bindGoogleFakes($drive, $docs);

        $this->actingAs($hr)
            ->postJson(
                '/api/weekly-plans/google-docs/import',
                $this->validPayload($requestId),
            )
            ->assertCreated()
            ->assertJsonPath('data.id', fn ($id): bool => $id === $concurrentPlan?->id);

        config()->set('weekly_plans.workflow_v3_enabled', true);
        $this->actingAs($hr)
            ->postJson(
                '/api/weekly-plans/google-docs/import',
                $this->validPayload($requestId) + ['workflow_version' => 3],
            )
            ->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_REQUEST_CONFLICT');

        $this->assertSame(2, $drive->calls);
        $this->assertSame(1, $docs->calls);
        $this->assertDatabaseCount('weekly_plans', 1);
        $this->assertDatabaseCount('weekly_plan_google_docs_sources', 1);
        $this->assertDatabaseCount('weekly_plan_source_passages', 0);
        $this->assertDatabaseCount('weekly_plan_events', 0);
    }

    #[DataProvider('authorizationMutationScenarios')]
    public function test_import_revalidates_membership_permission_and_organization_after_google_read(
        string $scenario,
    ): void {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $drive = new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]);
        $docs = new SourceGoogleDocsClient(
            $this->document(),
            function () use ($hr, $scenario): void {
                if ($scenario === 'inactive') {
                    $this->deactivateMembership($hr);

                    return;
                }
                if ($scenario === 'permission') {
                    $this->revokeManageDraft($hr);

                    return;
                }

                $other = Organization::query()->create([
                    'code' => 'OTHER_CHANGED_DURING_READ',
                    'name' => 'Other Changed During Read',
                    'type' => 'internal',
                    'is_active' => true,
                ]);
                DB::table('organization_memberships')
                    ->where('user_id', $hr->id)
                    ->update(['organization_id' => $other->id]);
            },
        );
        $this->bindGoogleFakes($drive, $docs);

        $this->actingAs($hr)
            ->postJson('/api/weekly-plans/google-docs/import', $this->validPayload())
            ->assertForbidden();

        $this->assertSame(2, $drive->calls);
        $this->assertSame(1, $docs->calls);
        $this->assertDatabaseCount('weekly_plans', 0);
        $this->assertDatabaseCount('weekly_plan_google_docs_sources', 0);
        $this->assertDatabaseCount('weekly_plan_source_passages', 0);
        $this->assertDatabaseCount('weekly_plan_events', 0);
    }

    /** @return array<string, array{string}> */
    public static function authorizationMutationScenarios(): array
    {
        return [
            'inactive membership' => ['inactive'],
            'revoked permission' => ['permission'],
            'changed organization' => ['organization'],
        ];
    }

    public function test_revision_id_can_be_null_without_weakening_snapshot_consistency(): void
    {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $document = $this->document();
        unset($document['revisionId']);
        $snapshotSha256 = $this->snapshotSha256For($document);
        $this->bindGoogleFakes(
            new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]),
            new SourceGoogleDocsClient($document),
        );

        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', [
            'document_url' => $this->documentUrl(),
            'expected_snapshot_sha256' => $snapshotSha256,
            'request_id' => '7c2f08e4-8b9d-4645-9b30-c6a1470d7130',
        ])->assertCreated()
            ->assertJsonPath('data.source_document.google_docs.snapshot_sha256', $snapshotSha256);

        $source = WeeklyPlanGoogleDocsSource::query()->firstOrFail();
        $this->assertNull($source->revision_id);
        $this->assertSame($snapshotSha256, $source->snapshot_sha256);
    }

    public function test_resource_defensively_allowlists_persisted_tab_manifest(): void
    {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $this->bindGoogleFakes(
            new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]),
            new SourceGoogleDocsClient($this->document()),
        );
        $planId = $this->actingAs($hr)
            ->postJson('/api/weekly-plans/google-docs/import', $this->validPayload())
            ->assertCreated()
            ->json('data.id');
        $source = WeeklyPlanGoogleDocsSource::query()->firstOrFail();
        $manifest = $source->tab_manifest;
        $manifest[0]['provider_payload'] = ['secret' => true];
        $manifest[0]['source_group']['unexpected'] = 'hidden';
        $manifest[0]['source_group']['evidence']['internal_document_id'] = self::DOCUMENT_ID;
        $source->update(['tab_manifest' => $manifest]);
        $plan = WeeklyPlan::query()->with('googleDocsSource')->findOrFail($planId);

        $payload = (new WeeklyPlanResource($plan))->resolve(request());
        $tab = $payload['source_document']['google_docs']['tabs'][0];
        $this->assertArrayNotHasKey('provider_payload', $tab);
        $this->assertArrayNotHasKey('unexpected', $tab['source_group']);
        $this->assertArrayNotHasKey('internal_document_id', $tab['source_group']['evidence']);
        $this->assertSame('tab-root', $tab['tab_id']);
        $this->assertFalse($tab['source_group']['identity_verified']);
    }

    #[DataProvider('existingPlanScenarios')]
    public function test_existing_empty_populated_or_locked_plan_is_never_replaced(
        string $status,
        bool $populated,
    ): void {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $plan = $this->existingPlan($organizationId, $hr, WeeklyPlanStatus::from($status));
        if ($populated) {
            DB::table('weekly_plan_entries')->insert([
                'weekly_plan_id' => $plan->id,
                'title' => 'Existing entry',
                'sort_order' => 1,
                'validation_status' => 'VALID',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $drive = new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]);
        $docs = new SourceGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);

        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', $this->validPayload())
            ->assertConflict()
            ->assertJsonPath('code', 'WEEKLY_PLAN_ALREADY_EXISTS');

        $this->assertSame(0, $drive->calls);
        $this->assertSame(0, $docs->calls);
        $this->assertDatabaseCount('weekly_plans', 1);
        $this->assertDatabaseCount('weekly_plan_google_docs_sources', 0);
    }

    /** @return array<string, array{string, bool}> */
    public static function existingPlanScenarios(): array
    {
        return [
            'empty draft' => [WeeklyPlanStatus::DRAFT->value, false],
            'populated draft' => [WeeklyPlanStatus::DRAFT->value, true],
            'submitted' => [WeeklyPlanStatus::SUBMITTED->value, false],
            'approved' => [WeeklyPlanStatus::APPROVED->value, false],
            'published' => [WeeklyPlanStatus::PUBLISHED->value, false],
        ];
    }

    public function test_client_cannot_supply_organization_document_passages_or_assignee(): void
    {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $drive = new SourceSequenceDriveClient([$this->metadata()]);
        $docs = new SourceGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);

        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', $this->validPayload() + [
            'organization_id' => $organizationId,
            'user_id' => $hr->id,
            'document_id' => self::DOCUMENT_ID,
            'passages' => [['text' => 'untrusted']],
            'assignee_id' => $hr->id,
        ])->assertUnprocessable();

        $this->assertSame(0, $drive->calls);
        $this->assertSame(0, $docs->calls);
        $this->assertDatabaseCount('weekly_plans', 0);
    }

    #[DataProvider('authorizationFailureScenarios')]
    public function test_import_reuses_fail_closed_folder_authorization(
        string $scenario,
        int $expectedStatus,
        string $expectedCode,
    ): void {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $before = $this->metadata();
        $after = $this->metadata();

        if ($scenario === 'missing') {
            // Intentionally no registry row.
        } elseif ($scenario === 'inactive') {
            $folder = $this->approveFolder($organizationId, $hr);
            $folder->update(['is_active' => false]);
        } elseif ($scenario === 'cross_organization') {
            $other = Organization::query()->create([
                'code' => 'OTHER_GOOGLE_SOURCE',
                'name' => 'Other Google Source Organization',
                'type' => 'internal',
                'is_active' => true,
            ]);
            $this->approveFolder($other->id, $hr);
        } else {
            $this->approveFolder($organizationId, $hr);
        }

        if ($scenario === 'wrong_mime') {
            $before['mime_type'] = 'application/pdf';
        } elseif ($scenario === 'shortcut') {
            $before['mime_type'] = 'application/vnd.google-apps.shortcut';
        } elseif ($scenario === 'nested') {
            $before['parents'] = ['NestedFolder_1234567890AbCdEfGhIj'];
        } elseif ($scenario === 'moved') {
            $after['parents'] = ['MovedFolder_1234567890AbCdEfGhIj'];
        }

        $this->bindGoogleFakes(
            new SourceSequenceDriveClient([$before, $after]),
            new SourceGoogleDocsClient($this->document()),
        );

        $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', $this->validPayload())
            ->assertStatus($expectedStatus)
            ->assertJsonPath('code', $expectedCode)
            ->assertJsonMissing(['document_id' => self::DOCUMENT_ID]);

        $this->assertDatabaseCount('weekly_plans', 0);
        $this->assertDatabaseCount('weekly_plan_google_docs_sources', 0);
    }

    /** @return array<string, array{string, int, string}> */
    public static function authorizationFailureScenarios(): array
    {
        return [
            'missing registry' => ['missing', 404, 'GOOGLE_DOCS_NOT_AVAILABLE'],
            'inactive registry' => ['inactive', 404, 'GOOGLE_DOCS_NOT_AVAILABLE'],
            'cross organization registry' => ['cross_organization', 404, 'GOOGLE_DOCS_NOT_AVAILABLE'],
            'wrong MIME' => ['wrong_mime', 404, 'GOOGLE_DOCS_NOT_AVAILABLE'],
            'shortcut' => ['shortcut', 404, 'GOOGLE_DOCS_NOT_AVAILABLE'],
            'nested folder' => ['nested', 404, 'GOOGLE_DOCS_NOT_AVAILABLE'],
            'moved between checks' => ['moved', 409, 'GOOGLE_DOCS_AUTHORIZATION_CHANGED'],
        ];
    }

    public function test_unauthorized_or_inactive_membership_is_rejected_before_google_calls(): void
    {
        $unauthorized = User::factory()->create();
        $unauthorizedMembership = $this->createMembership($unauthorized);
        $inactive = User::factory()->create();
        $this->createMembership($inactive, false);
        $drive = new SourceSequenceDriveClient([$this->metadata()]);
        $docs = new SourceGoogleDocsClient($this->document());
        $this->bindGoogleFakes($drive, $docs);
        $this->approveFolder($unauthorizedMembership['organization_id'], $unauthorized);

        $this->actingAs($unauthorized)->postJson('/api/weekly-plans/google-docs/import', $this->validPayload())
            ->assertForbidden();
        $this->actingAs($inactive)->postJson('/api/weekly-plans/google-docs/import', $this->validPayload())
            ->assertUnauthorized();

        $this->assertSame(0, $drive->calls);
        $this->assertSame(0, $docs->calls);
        $this->assertDatabaseCount('weekly_plans', 0);
    }

    public function test_database_failure_rolls_back_plan_source_passages_and_events(): void
    {
        [$hr, $organizationId] = $this->userWithManageDraft();
        $this->approveFolder($organizationId, $hr);
        $this->bindGoogleFakes(
            new SourceSequenceDriveClient([$this->metadata(), $this->metadata()]),
            new SourceGoogleDocsClient($this->document()),
        );
        WeeklyPlanGoogleDocsSource::creating(static function (): void {
            throw new RuntimeException('Synthetic database failure.');
        });

        try {
            $this->withoutExceptionHandling();
            $thrown = null;
            try {
                $this->actingAs($hr)->postJson('/api/weekly-plans/google-docs/import', $this->validPayload());
            } catch (RuntimeException $exception) {
                $thrown = $exception;
            }
            $this->assertNotNull($thrown);
            $this->assertSame('Synthetic database failure.', $thrown->getMessage());
        } finally {
            WeeklyPlanGoogleDocsSource::flushEventListeners();
        }

        $this->assertDatabaseCount('weekly_plans', 0);
        $this->assertDatabaseCount('weekly_plan_google_docs_sources', 0);
        $this->assertDatabaseCount('weekly_plan_source_passages', 0);
        $this->assertDatabaseCount('weekly_plan_events', 0);
    }

    /** @return array{User, int} */
    private function userWithManageDraft(): array
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
        $roleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $roleId, true);
        $this->grantPermission(
            $roleId,
            $this->createPermission('weekly_plans.manage_draft'),
            DataScope::ORGANIZATION->value,
        );

        return [$user, $membership['organization_id']];
    }

    private function revokeManageDraft(User $user): void
    {
        $permissionId = DB::table('permissions')
            ->where('code', 'weekly_plans.manage_draft')
            ->value('id');
        DB::table('role_permissions')
            ->where('permission_id', $permissionId)
            ->whereIn('role_id', $this->roleIdsFor($user))
            ->delete();
    }

    private function grantManageDraftToCurrentRole(User $user): void
    {
        $permissionId = (int) DB::table('permissions')
            ->where('code', 'weekly_plans.manage_draft')
            ->value('id');
        $roleId = $this->roleIdsFor($user)[0];
        $this->grantPermission(
            $roleId,
            $permissionId,
            DataScope::ORGANIZATION->value,
        );
    }

    private function deactivateMembership(User $user): void
    {
        DB::table('organization_memberships')
            ->where('user_id', $user->id)
            ->update(['is_active' => false]);
    }

    /** @return list<int> */
    private function roleIdsFor(User $user): array
    {
        $membershipId = DB::table('organization_memberships')
            ->where('user_id', $user->id)
            ->value('id');

        return DB::table('membership_roles')
            ->where('membership_id', $membershipId)
            ->orderBy('role_id')
            ->pluck('role_id')
            ->map(fn ($roleId): int => (int) $roleId)
            ->all();
    }

    private function approveFolder(int $organizationId, User $actor): OrganizationGoogleDocsFolder
    {
        return OrganizationGoogleDocsFolder::query()->create([
            'organization_id' => $organizationId,
            'google_folder_id' => self::APPROVED_FOLDER,
            'is_active' => true,
            'approved_by_user_id' => $actor->id,
            'approved_at' => now(),
        ]);
    }

    private function existingPlan(int $organizationId, User $actor, WeeklyPlanStatus $status): WeeklyPlan
    {
        return WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $actor->id,
            'week_start' => '2026-11-02',
            'week_end' => '2026-11-07',
            'status' => $status,
            'source_type' => WeeklyPlanSourceType::UPLOADED_FILE,
            'original_filename' => 'existing.docx',
            'source_extension' => 'docx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'source_size' => 1,
            'source_sha256' => str_repeat('e', 64),
            'parse_status' => 'PARSED',
            'uploaded_at' => now(),
        ]);
    }

    private function bindGoogleFakes(SourceSequenceDriveClient $drive, SourceGoogleDocsClient $docs): void
    {
        $this->app->instance(GoogleDriveFileMetadataClient::class, $drive);
        $this->app->instance(GoogleDocsApiClient::class, $docs);
    }

    /** @return array<string, mixed> */
    private function validPayload(?string $requestId = null): array
    {
        return [
            'document_url' => $this->documentUrl(),
            'expected_snapshot_sha256' => $this->snapshotSha256(),
            'request_id' => $requestId ?? '06c958ed-49df-4bb5-a3fe-a64380321466',
        ];
    }

    private function snapshotSha256(): string
    {
        return hash('sha256', self::DOCUMENT_ID.'|'.self::REVISION);
    }

    /** @param array<string, mixed> $document */
    private function snapshotSha256For(array $document): string
    {
        $encoded = json_encode(
            $document,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return hash('sha256', self::DOCUMENT_ID.'|'.hash('sha256', $encoded));
    }

    private function documentUrl(): string
    {
        return 'https://docs.google.com/document/d/'.self::DOCUMENT_ID.'/edit';
    }

    /** @return array{id: string, mime_type: string, parents: list<string>} */
    private function metadata(): array
    {
        return [
            'id' => self::DOCUMENT_ID,
            'mime_type' => 'application/vnd.google-apps.document',
            'parents' => [self::APPROVED_FOLDER],
        ];
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        $root = $this->tab('tab-root', 'Gốc', 0, '');
        $root['childTabs'] = [
            $this->tab('tab-child', 'Tên trùng', 0, 'Đầu việc thứ hai', 'tab-root'),
        ];

        return [
            'documentId' => self::DOCUMENT_ID,
            'title' => 'Kế hoạch tuần giả lập',
            'revisionId' => self::REVISION,
            'tabs' => [
                $root,
                $this->tab('tab-second', 'Tên trùng', 1, 'Đầu việc thứ nhất'),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function tab(string $id, string $title, int $index, string $text, ?string $parentId = null): array
    {
        $properties = ['tabId' => $id, 'title' => $title, 'index' => $index];
        if ($parentId !== null) {
            $properties['parentTabId'] = $parentId;
        }

        return [
            'tabProperties' => $properties,
            'documentTab' => [
                'body' => [
                    'content' => $text === '' ? [] : [[
                        'paragraph' => [
                            'elements' => [['textRun' => ['content' => $text."\n"]]],
                        ],
                    ]],
                ],
            ],
            'childTabs' => [],
        ];
    }
}

final class SourceSequenceDriveClient implements GoogleDriveFileMetadataClient
{
    public int $calls = 0;

    /** @param list<array{id: string, mime_type: string, parents: list<string>}> $responses */
    public function __construct(private readonly array $responses) {}

    public function getFileMetadata(string $fileId, int $timeoutSeconds, int $maxResponseBytes): array
    {
        $response = $this->responses[$this->calls]
            ?? $this->responses[array_key_last($this->responses)]
            ?? [];
        $this->calls++;

        return $response;
    }
}

final class SourceGoogleDocsClient implements GoogleDocsApiClient
{
    public int $calls = 0;

    public bool $lastIncludeTabsContent = false;

    /** @param array<string, mixed> $document */
    public function __construct(
        private readonly array $document,
        private readonly ?\Closure $beforeReturn = null,
    ) {}

    public function getDocument(
        string $documentId,
        bool $includeTabsContent,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array {
        $this->calls++;
        $this->lastIncludeTabsContent = $includeTabsContent;
        ($this->beforeReturn ?? static fn () => null)();

        return $this->document;
    }
}
