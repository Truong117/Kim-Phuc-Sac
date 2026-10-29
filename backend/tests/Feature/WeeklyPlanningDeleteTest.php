<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\WeeklyPlanParseStatus;
use App\Enums\WeeklyPlanSourceType;
use App\Enums\WeeklyPlanStatus;
use App\Models\AiUsageRecord;
use App\Models\OrganizationGoogleDocsFolder;
use App\Models\Task;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanGoogleDocsSource;
use App\Models\WeeklyPlanGoogleDocsTabMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningDeleteTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_draft_with_manage_draft_can_be_deleted_and_list_actions_are_authoritative(): void
    {
        [$manager, $organizationId] = $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
        ]);
        $draft = $this->plan($manager, $organizationId);
        $locked = $this->plan($manager, $organizationId, WeeklyPlanStatus::SUBMITTED, '2026-11-09');

        $this->actingAs($manager)->getJson('/api/weekly-plans')
            ->assertOk()
            ->assertJsonPath('data.0.id', $locked->id)
            ->assertJsonPath('data.0.actions.can_delete', false)
            ->assertJsonPath('data.1.id', $draft->id)
            ->assertJsonPath('data.1.actions.can_delete', true);
        $this->actingAs($manager)->getJson("/api/weekly-plans/{$draft->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_delete', true);
        $this->actingAs($manager)->getJson("/api/weekly-plans/{$locked->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_delete', false);

        $this->actingAs($manager)
            ->deleteJson("/api/weekly-plans/{$draft->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('weekly_plans', ['id' => $draft->id]);
        $this->assertDatabaseHas('weekly_plans', ['id' => $locked->id]);
    }

    public function test_draft_without_manage_draft_is_forbidden(): void
    {
        [$manager, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        [$viewer] = $this->userWithPermissions(['weekly_plans.view']);
        $plan = $this->plan($manager, $organizationId);

        $this->actingAs($viewer)->deleteJson("/api/weekly-plans/{$plan->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('weekly_plans', ['id' => $plan->id]);
    }

    public function test_plan_in_another_organization_cannot_be_deleted(): void
    {
        [$manager] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        [$otherManager, $otherOrganizationId] = $this->userWithPermissions(
            ['weekly_plans.manage_draft'],
            'OTHER',
        );
        $plan = $this->plan($otherManager, $otherOrganizationId);

        $this->actingAs($manager)->deleteJson("/api/weekly-plans/{$plan->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('weekly_plans', ['id' => $plan->id]);
    }

    #[DataProvider('nonDraftStatuses')]
    public function test_non_draft_statuses_cannot_be_deleted(WeeklyPlanStatus $status): void
    {
        [$manager, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $plan = $this->plan($manager, $organizationId, $status);

        $this->actingAs($manager)->deleteJson("/api/weekly-plans/{$plan->id}")
            ->assertConflict();

        $this->assertDatabaseHas('weekly_plans', ['id' => $plan->id]);
    }

    /** @return iterable<string, array{WeeklyPlanStatus}> */
    public static function nonDraftStatuses(): iterable
    {
        yield 'submitted' => [WeeklyPlanStatus::SUBMITTED];
        yield 'in review' => [WeeklyPlanStatus::IN_REVIEW];
        yield 'approved' => [WeeklyPlanStatus::APPROVED];
        yield 'published' => [WeeklyPlanStatus::PUBLISHED];
        yield 'changes requested' => [WeeklyPlanStatus::CHANGES_REQUESTED];
    }

    public function test_locked_status_is_rechecked_instead_of_using_a_stale_draft_model(): void
    {
        [$manager, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $staleDraft = $this->plan($manager, $organizationId);
        DB::table('weekly_plans')->where('id', $staleDraft->id)->update([
            'status' => WeeklyPlanStatus::SUBMITTED->value,
        ]);

        $this->assertSame(WeeklyPlanStatus::DRAFT, $staleDraft->status);
        $this->actingAs($manager)->deleteJson("/api/weekly-plans/{$staleDraft->id}")
            ->assertConflict();

        $this->assertDatabaseHas('weekly_plans', [
            'id' => $staleDraft->id,
            'status' => WeeklyPlanStatus::SUBMITTED->value,
        ]);
    }

    public function test_unexpected_published_task_blocks_delete_and_preserves_source_file(): void
    {
        Storage::fake('weekly-plans-test');
        [$manager, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $employee = $this->employee($organizationId);
        $path = 'weekly-plans/source.docx';
        Storage::disk('weekly-plans-test')->put($path, 'private source');
        $plan = $this->plan($manager, $organizationId, sourceDisk: 'weekly-plans-test', sourcePath: $path);
        $entry = $plan->entries()->create($this->entryAttributes($employee));
        Task::query()->create([
            'organization_id' => $organizationId,
            'creator_id' => $manager->id,
            'assignee_id' => $employee->id,
            'title' => 'Published task',
            'priority' => 'MEDIUM',
            'status' => 'TODO',
            'due_date' => '2026-11-07',
            'weekly_plan_entry_id' => $entry->id,
        ]);

        $this->actingAs($manager)->deleteJson("/api/weekly-plans/{$plan->id}")
            ->assertConflict();

        $this->assertDatabaseHas('weekly_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('tasks', ['weekly_plan_entry_id' => $entry->id]);
        Storage::disk('weekly-plans-test')->assertExists($path);
    }

    public function test_google_docs_delete_respects_restrict_fk_order_and_isolates_other_plan(): void
    {
        [$manager, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $employee = $this->employee($organizationId);
        $plan = $this->googlePlan($manager, $organizationId, $employee, '2026-11-02');
        $otherPlan = $this->googlePlan($manager, $organizationId, $employee, '2026-11-09');
        $passage = $plan->sourcePassages()->create([
            'source_sha256' => $plan->source_sha256,
            'source_key' => 'google-tab:work:paragraph:1',
            'sequence' => 1,
            'kind' => 'paragraph',
            'text' => 'Source work',
            'content_sha256' => hash('sha256', 'Source work'),
        ]);
        $entry = $plan->entries()->create($this->entryAttributes($employee));
        $entry->sourcePassages()->attach($passage->id);
        $item = $entry->items()->create([
            'title' => 'Child item',
            'sort_order' => 1,
        ]);
        DB::table('weekly_plan_entry_item_source_passage')->insert([
            'weekly_plan_entry_item_id' => $item->id,
            'weekly_plan_source_passage_id' => $passage->id,
        ]);

        $this->actingAs($manager)->deleteJson("/api/weekly-plans/{$plan->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('weekly_plans', ['id' => $plan->id]);
        $this->assertDatabaseMissing('weekly_plan_google_docs_sources', ['weekly_plan_id' => $plan->id]);
        $this->assertDatabaseMissing('weekly_plan_source_passages', ['id' => $passage->id]);
        $this->assertDatabaseMissing('weekly_plan_entries', ['id' => $entry->id]);
        $this->assertDatabaseMissing('weekly_plan_entry_items', ['id' => $item->id]);
        $this->assertDatabaseMissing('weekly_plan_entry_source_passage', [
            'weekly_plan_entry_id' => $entry->id,
        ]);
        $this->assertDatabaseMissing('weekly_plan_entry_item_source_passage', [
            'weekly_plan_entry_item_id' => $item->id,
        ]);
        $this->assertDatabaseMissing('weekly_plan_google_docs_tab_mappings', ['weekly_plan_id' => $plan->id]);
        $this->assertDatabaseMissing('weekly_plan_google_docs_tab_mapping_events', ['weekly_plan_id' => $plan->id]);
        $this->assertDatabaseHas('weekly_plans', ['id' => $otherPlan->id]);
        $this->assertDatabaseHas('weekly_plan_google_docs_tab_mappings', ['weekly_plan_id' => $otherPlan->id]);
        $this->assertDatabaseHas('weekly_plan_google_docs_tab_mapping_events', ['weekly_plan_id' => $otherPlan->id]);
    }

    public function test_ai_usage_history_survives_draft_deletion(): void
    {
        [$manager, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $plan = $this->plan($manager, $organizationId);
        $usage = $this->usage($manager, $organizationId);

        $this->actingAs($manager)->deleteJson("/api/weekly-plans/{$plan->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('weekly_plans', ['id' => $plan->id]);
        $this->assertDatabaseHas('ai_usage_records', ['id' => $usage->id]);
    }

    public function test_uploaded_source_file_is_removed_only_after_successful_database_delete(): void
    {
        Storage::fake('weekly-plans-test');
        [$manager, $organizationId] = $this->userWithPermissions(['weekly_plans.manage_draft']);
        $path = 'weekly-plans/source.docx';
        Storage::disk('weekly-plans-test')->put($path, 'private source');
        $plan = $this->plan($manager, $organizationId, sourceDisk: 'weekly-plans-test', sourcePath: $path);

        $this->actingAs($manager)->deleteJson("/api/weekly-plans/{$plan->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('weekly_plans', ['id' => $plan->id]);
        Storage::disk('weekly-plans-test')->assertMissing($path);
    }

    /** @return array{User, int} */
    private function userWithPermissions(array $permissions, string $organizationCode = 'KPS'): array
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user, organizationCode: $organizationCode);
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

    private function employee(int $organizationId): User
    {
        $employee = User::factory()->create();
        $organizationCode = (string) DB::table('organizations')->where('id', $organizationId)->value('code');
        $this->createMembership($employee, organizationCode: $organizationCode);

        return $employee;
    }

    private function plan(
        User $creator,
        int $organizationId,
        WeeklyPlanStatus $status = WeeklyPlanStatus::DRAFT,
        string $weekStart = '2026-11-02',
        ?string $sourceDisk = null,
        ?string $sourcePath = null,
    ): WeeklyPlan {
        return WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $creator->id,
            'week_start' => $weekStart,
            'week_end' => date('Y-m-d', strtotime($weekStart.' +5 days')),
            'status' => $status,
            'source_type' => WeeklyPlanSourceType::UPLOADED_FILE,
            'source_disk' => $sourceDisk,
            'source_path' => $sourcePath,
            'original_filename' => 'plan.docx',
            'source_extension' => 'docx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'source_size' => 100,
            'source_sha256' => hash('sha256', $weekStart),
            'parse_status' => WeeklyPlanParseStatus::PARSED,
            'uploaded_at' => now(),
        ]);
    }

    private function googlePlan(User $creator, int $organizationId, User $employee, string $weekStart): WeeklyPlan
    {
        $plan = $this->plan($creator, $organizationId, weekStart: $weekStart);
        $plan->forceFill([
            'source_type' => WeeklyPlanSourceType::GOOGLE_DOCS,
            'original_filename' => 'Google Docs',
            'source_extension' => 'gdoc',
            'source_mime' => 'application/vnd.google-apps.document',
        ])->save();
        $folder = OrganizationGoogleDocsFolder::query()->firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'google_folder_id' => 'folder-'.$plan->id,
                'is_active' => true,
                'approved_by_user_id' => $creator->id,
                'approved_at' => now(),
            ],
        );
        $documentHash = hash('sha256', 'document-'.$plan->id);
        WeeklyPlanGoogleDocsSource::query()->create([
            'weekly_plan_id' => $plan->id,
            'organization_id' => $organizationId,
            'approved_folder_id' => $folder->id,
            'confirmed_by_user_id' => $creator->id,
            'request_id' => fake()->uuid(),
            'request_payload_sha256' => hash('sha256', 'request-'.$plan->id),
            'google_document_id' => 'document-'.$plan->id,
            'document_id_sha256' => $documentHash,
            'approved_folder_id_sha256' => hash('sha256', $folder->google_folder_id),
            'document_title' => 'Weekly plan',
            'snapshot_sha256' => $plan->source_sha256,
            'tab_manifest' => [],
            'passage_count' => 0,
            'character_count' => 0,
            'captured_at' => now(),
            'last_verified_at' => now(),
        ]);
        $mapping = WeeklyPlanGoogleDocsTabMapping::query()->create([
            'organization_id' => $organizationId,
            'weekly_plan_id' => $plan->id,
            'document_id_sha256' => $documentHash,
            'tab_id_sha256' => hash('sha256', 'tab-'.$plan->id),
            'tab_title_sha256' => hash('sha256', 'work'),
            'status' => 'VERIFIED',
            'assignee_user_id' => $employee->id,
            'verified_by_user_id' => $creator->id,
            'verified_at' => now(),
            'version' => 1,
        ]);
        DB::table('weekly_plan_google_docs_tab_mapping_events')->insert([
            'mapping_id' => $mapping->id,
            'organization_id' => $organizationId,
            'weekly_plan_id' => $plan->id,
            'actor_user_id' => $creator->id,
            'new_status' => 'VERIFIED',
            'new_assignee_user_id' => $employee->id,
            'tab_title_sha256' => hash('sha256', 'work'),
            'mapping_version' => 1,
            'created_at' => now(),
        ]);

        return $plan->fresh();
    }

    /** @return array<string, mixed> */
    private function entryAttributes(User $employee): array
    {
        return [
            'assignee_id' => $employee->id,
            'title' => 'Weekly work',
            'expected_result' => 'Completed',
            'start_date' => '2026-11-02',
            'due_date' => '2026-11-07',
            'priority' => 'MEDIUM',
            'sort_order' => 1,
            'validation_status' => 'VALID',
            'validation_errors' => [],
        ];
    }

    private function usage(User $manager, int $organizationId): AiUsageRecord
    {
        $periodId = DB::table('ai_budget_periods')->insertGetId([
            'environment' => 'testing',
            'provider' => 'openai',
            'billing_period' => '2026-11',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return AiUsageRecord::query()->create([
            'ai_budget_period_id' => $periodId,
            'organization_id' => $organizationId,
            'requested_by_user_id' => $manager->id,
            'feature' => 'WEEKLY_PLAN_EXTRACTION',
            'request_fingerprint' => hash('sha256', fake()->uuid()),
            'status' => 'COMPLETED',
            'provider' => 'openai',
            'model' => 'test-model',
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
