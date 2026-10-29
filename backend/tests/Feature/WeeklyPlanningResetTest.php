<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\WeeklyPlanGoogleDocsMappingStatus;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningResetTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_manage_draft_and_draft_status_are_required(): void
    {
        [$manager, $organizationId] = $this->manager();
        [$viewer] = $this->userWithPermissions(['weekly_plans.view']);
        $plan = $this->plan($manager, $organizationId);

        $this->actingAs($viewer)
            ->postJson("/api/weekly-plans/{$plan->id}/reset")
            ->assertForbidden();

        $plan->forceFill(['status' => WeeklyPlanStatus::SUBMITTED])->save();

        $this->actingAs($manager)
            ->postJson("/api/weekly-plans/{$plan->id}/reset")
            ->assertConflict();

        $this->assertDatabaseCount('weekly_plans', 1);
    }

    public function test_reset_deletes_generated_work_and_review_state_but_retains_source_and_audit_history(): void
    {
        [$manager, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId);
        $plan = $this->googlePlan($manager, $organizationId, $employee);
        $passage = $plan->sourcePassages()->where('sequence', 1)->firstOrFail();
        $entry = $plan->entries()->create($this->entryAttributes($employee));
        $entry->sourcePassages()->attach($passage->id);
        $item = $entry->items()->create([
            'title' => 'Child item',
            'content' => 'Child content',
            'sort_order' => 1,
        ]);
        DB::table('weekly_plan_entry_item_source_passage')->insert([
            'weekly_plan_entry_item_id' => $item->id,
            'weekly_plan_source_passage_id' => $passage->id,
        ]);
        $plan->suggestions()->create([
            'weekly_plan_entry_id' => $entry->id,
            'type' => 'EXPECTED_RESULT',
            'original_value' => null,
            'suggested_value' => 'Measurable result',
            'reason' => 'Rule check',
            'status' => 'PENDING',
        ]);
        $plan->employeeReviews()->create([
            'assignee_id' => $employee->id,
            'status' => 'PENDING',
        ]);
        $plan->comments()->create([
            'weekly_plan_entry_id' => $entry->id,
            'author_id' => $manager->id,
            'content' => 'Entry comment',
            'created_at' => now(),
        ]);
        $planCommentId = $plan->comments()->create([
            'author_id' => $manager->id,
            'content' => 'Plan comment',
            'created_at' => now(),
        ])->id;
        $analysisEventId = $plan->events()->create([
            'actor_id' => $manager->id,
            'event_type' => 'ANALYZED',
            'metadata' => ['fingerprint' => hash('sha256', 'entries')],
            'created_at' => now(),
        ])->id;
        $applicationId = $plan->entryApplications()->create([
            'created_by' => $manager->id,
            'idempotency_key' => fake()->uuid(),
            'payload_sha256' => hash('sha256', 'payload'),
            'entry_ids' => [$entry->id],
            'created_at' => now(),
        ])->id;
        $usageId = $this->usage($manager, $organizationId)->id;

        $this->actingAs($manager)
            ->postJson("/api/weekly-plans/{$plan->id}/reset")
            ->assertOk()
            ->assertJsonPath('data.summary.entry_count', 0)
            ->assertJsonPath('data.summary.item_count', 0)
            ->assertJsonPath('data.source_document.google_docs.processed_tab_ids', [])
            ->assertJsonPath('data.source_document.google_docs.remaining_tab_ids', ['tab-work'])
            ->assertJsonPath('data.source_document.google_docs.processed_tab_count', 0)
            ->assertJsonPath('data.source_document.google_docs.relevant_tab_count', 1);

        foreach ([
            'weekly_plan_entries',
            'weekly_plan_entry_items',
            'weekly_plan_entry_source_passage',
            'weekly_plan_entry_item_source_passage',
            'weekly_plan_suggestions',
            'weekly_plan_employee_reviews',
        ] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseMissing('weekly_plan_comments', ['content' => 'Entry comment']);
        $this->assertDatabaseHas('weekly_plan_comments', ['id' => $planCommentId]);
        $this->assertDatabaseHas('weekly_plan_source_passages', ['weekly_plan_id' => $plan->id]);
        $this->assertDatabaseHas('weekly_plan_google_docs_sources', ['weekly_plan_id' => $plan->id]);
        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mappings', 2);
        $this->assertDatabaseCount('weekly_plan_google_docs_tab_mapping_events', 2);
        $this->assertDatabaseHas('weekly_plan_events', ['id' => $analysisEventId]);
        $this->assertDatabaseHas('weekly_plan_entry_applications', ['id' => $applicationId]);
        $this->assertDatabaseHas('ai_usage_records', ['id' => $usageId]);

        $event = DB::table('weekly_plan_events')
            ->where('weekly_plan_id', $plan->id)
            ->where('event_type', 'PLAN_RESET')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame([
            'entry_count' => 1,
            'item_count' => 1,
            'suggestion_count' => 1,
            'employee_review_count' => 1,
            'entry_comment_count' => 1,
        ], json_decode($event->metadata, true, flags: JSON_THROW_ON_ERROR));
        $this->assertDatabaseHas('weekly_plan_google_docs_tab_mappings', [
            'weekly_plan_id' => $plan->id,
            'status' => WeeklyPlanGoogleDocsMappingStatus::NOT_APPLICABLE->value,
            'assignee_user_id' => null,
        ]);
    }

    public function test_reset_fails_closed_and_rolls_back_when_a_published_task_exists(): void
    {
        [$manager, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId);
        $plan = $this->plan($manager, $organizationId);
        $entry = $plan->entries()->create($this->entryAttributes($employee));
        $plan->suggestions()->create([
            'weekly_plan_entry_id' => $entry->id,
            'type' => 'EXPECTED_RESULT',
            'suggested_value' => 'Result',
            'reason' => 'Rule check',
            'status' => 'PENDING',
        ]);
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

        $this->actingAs($manager)
            ->postJson("/api/weekly-plans/{$plan->id}/reset")
            ->assertConflict();

        $this->assertDatabaseHas('weekly_plan_entries', ['id' => $entry->id]);
        $this->assertDatabaseHas('weekly_plan_suggestions', ['weekly_plan_entry_id' => $entry->id]);
        $this->assertDatabaseMissing('weekly_plan_events', [
            'weekly_plan_id' => $plan->id,
            'event_type' => 'PLAN_RESET',
        ]);
    }

    public function test_reset_transaction_rolls_back_every_delete_when_a_late_write_fails(): void
    {
        [$manager, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId);
        $plan = $this->plan($manager, $organizationId);
        $entry = $plan->entries()->create($this->entryAttributes($employee));
        $plan->suggestions()->create([
            'weekly_plan_entry_id' => $entry->id,
            'type' => 'EXPECTED_RESULT',
            'suggested_value' => 'Result',
            'reason' => 'Rule check',
            'status' => 'PENDING',
        ]);
        $plan->employeeReviews()->create([
            'assignee_id' => $employee->id,
            'status' => 'PENDING',
        ]);
        $plan->events()->create([
            'actor_id' => $manager->id,
            'event_type' => 'ANALYZED',
            'created_at' => now(),
        ]);

        DB::connection()->beforeExecuting(function (string $query, array $bindings): void {
            if (str_contains(strtolower($query), 'insert into "weekly_plan_events"')
                && in_array('PLAN_RESET', $bindings, true)) {
                throw new \RuntimeException('Synthetic late reset audit failure.');
            }
        });

        $this->actingAs($manager)
            ->postJson("/api/weekly-plans/{$plan->id}/reset")
            ->assertStatus(500);

        $this->assertDatabaseHas('weekly_plan_entries', ['id' => $entry->id]);
        $this->assertDatabaseHas('weekly_plan_suggestions', ['weekly_plan_entry_id' => $entry->id]);
        $this->assertDatabaseHas('weekly_plan_employee_reviews', ['weekly_plan_id' => $plan->id]);
        $this->assertDatabaseMissing('weekly_plan_events', [
            'weekly_plan_id' => $plan->id,
            'event_type' => 'SUGGESTIONS_INVALIDATED',
        ]);
        $this->assertDatabaseMissing('weekly_plan_events', [
            'weekly_plan_id' => $plan->id,
            'event_type' => 'PLAN_RESET',
        ]);
    }

    public function test_partial_google_docs_source_blocks_analysis_and_submission_and_deleted_evidence_reopens_it(): void
    {
        [$manager, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId);
        $plan = $this->googlePlan($manager, $organizationId, $employee);
        $passages = $plan->sourcePassages->keyBy(
            fn ($passage): string => (string) data_get($passage->metadata, 'tab_id'),
        );
        $workEntry = $plan->entries()->create($this->entryAttributes($employee));
        $workEntry->sourcePassages()->attach($passages->get('tab-work')->id);
        WeeklyPlanGoogleDocsTabMapping::query()
            ->where('weekly_plan_id', $plan->id)
            ->where('tab_id_sha256', hash('sha256', 'tab-note'))
            ->update([
                'status' => WeeklyPlanGoogleDocsMappingStatus::VERIFIED,
                'assignee_user_id' => $employee->id,
            ]);

        $this->actingAs($manager)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.source_document.google_docs.remaining_tab_ids', ['tab-note'])
            ->assertJsonPath('data.actions.can_analyze', false)
            ->assertJsonPath('data.actions.can_submit', false);
        $this->actingAs($manager)->postJson("/api/weekly-plans/{$plan->id}/analyze")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan');
        $this->actingAs($manager)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan');

        $noteEntry = $plan->entries()->create([
            ...$this->entryAttributes($employee),
            'title' => 'Second tab work',
            'sort_order' => 2,
        ]);
        $noteEntry->sourcePassages()->attach($passages->get('tab-note')->id);
        $this->actingAs($manager)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.source_document.google_docs.remaining_tab_ids', [])
            ->assertJsonPath('data.actions.can_analyze', true)
            ->assertJsonPath('data.actions.can_submit', true);

        $noteEntry->delete();
        $this->actingAs($manager)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.source_document.google_docs.remaining_tab_ids', ['tab-note'])
            ->assertJsonPath('data.actions.can_analyze', false)
            ->assertJsonPath('data.actions.can_submit', false);
        $this->actingAs($manager)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan');
    }

    public function test_not_applicable_tabs_do_not_block_google_docs_analysis_or_submission(): void
    {
        [$manager, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId);
        $plan = $this->googlePlan($manager, $organizationId, $employee);
        $passage = $plan->sourcePassages()->where('sequence', 1)->firstOrFail();
        $entry = $plan->entries()->create($this->entryAttributes($employee));
        $entry->sourcePassages()->attach($passage->id);

        $this->actingAs($manager)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.source_document.google_docs.remaining_tab_ids', [])
            ->assertJsonPath('data.actions.can_analyze', true)
            ->assertJsonPath('data.actions.can_submit', true);
        $this->actingAs($manager)->postJson("/api/weekly-plans/{$plan->id}/analyze")
            ->assertOk();
        $this->actingAs($manager)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', WeeklyPlanStatus::SUBMITTED->value);
    }

    #[DataProvider('uploadedSourceExtensions')]
    public function test_uploaded_plan_analysis_and_submission_remain_unchanged(string $extension): void
    {
        [$manager, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId);
        $plan = $this->plan($manager, $organizationId);
        $plan->forceFill([
            'original_filename' => "plan.{$extension}",
            'source_extension' => $extension,
        ])->save();
        $plan->entries()->create($this->entryAttributes($employee));

        $this->actingAs($manager)->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_analyze', true)
            ->assertJsonPath('data.actions.can_submit', true);
        $this->actingAs($manager)->postJson("/api/weekly-plans/{$plan->id}/analyze")
            ->assertOk();
        $this->actingAs($manager)->postJson("/api/weekly-plans/{$plan->id}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', WeeklyPlanStatus::SUBMITTED->value);
    }

    /** @return iterable<string, array{string}> */
    public static function uploadedSourceExtensions(): iterable
    {
        yield 'DOCX' => ['docx'];
        yield 'XLSX' => ['xlsx'];
    }

    /** @return array{User, int} */
    private function manager(): array
    {
        return $this->userWithPermissions([
            'weekly_plans.view',
            'weekly_plans.manage_draft',
            'weekly_plans.submit',
        ]);
    }

    /** @return array{User, int} */
    private function userWithPermissions(array $permissions): array
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);
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
        $this->createMembership($employee, organizationCode: 'KPS');

        return $employee;
    }

    private function plan(User $creator, int $organizationId): WeeklyPlan
    {
        return WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $creator->id,
            'week_start' => '2026-11-02',
            'week_end' => '2026-11-07',
            'status' => WeeklyPlanStatus::DRAFT,
            'source_type' => WeeklyPlanSourceType::UPLOADED_FILE,
            'original_filename' => 'plan.docx',
            'source_extension' => 'docx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'source_size' => 100,
            'source_sha256' => hash('sha256', 'source'),
            'parse_status' => WeeklyPlanParseStatus::PARSED,
            'uploaded_at' => now(),
        ]);
    }

    private function googlePlan(User $creator, int $organizationId, User $employee): WeeklyPlan
    {
        $plan = $this->plan($creator, $organizationId);
        $plan->forceFill([
            'source_type' => WeeklyPlanSourceType::GOOGLE_DOCS,
            'original_filename' => 'Google Docs',
            'source_extension' => 'gdoc',
            'source_mime' => 'application/vnd.google-apps.document',
        ])->save();
        $folder = OrganizationGoogleDocsFolder::query()->create([
            'organization_id' => $organizationId,
            'google_folder_id' => 'folder-'.$plan->id,
            'is_active' => true,
            'approved_by_user_id' => $creator->id,
            'approved_at' => now(),
        ]);
        $documentId = 'document-'.$plan->id;
        $documentHash = hash('sha256', $documentId);
        $manifest = [
            $this->manifestTab('tab-work', 'Employee work', 0),
            $this->manifestTab('tab-note', 'Instructions', 1),
        ];
        WeeklyPlanGoogleDocsSource::query()->create([
            'weekly_plan_id' => $plan->id,
            'organization_id' => $organizationId,
            'approved_folder_id' => $folder->id,
            'confirmed_by_user_id' => $creator->id,
            'request_id' => fake()->uuid(),
            'request_payload_sha256' => hash('sha256', 'request'),
            'google_document_id' => $documentId,
            'document_id_sha256' => $documentHash,
            'approved_folder_id_sha256' => hash('sha256', $folder->google_folder_id),
            'document_title' => 'Weekly plan',
            'snapshot_sha256' => $plan->source_sha256,
            'tab_manifest' => $manifest,
            'passage_count' => 2,
            'character_count' => 40,
            'captured_at' => now(),
            'last_verified_at' => now(),
        ]);
        foreach ($manifest as $index => $tab) {
            $text = 'Source '.$tab['tab_id'];
            $context = [
                'version' => 1,
                'key' => 'google-docs-tab:'.hash('sha256', $tab['tab_id']),
                'title' => $tab['title'],
                'origin' => 'GOOGLE_DOCS_TAB',
                'order' => $index + 1,
                'identity_verified' => false,
                'evidence' => [
                    'type' => 'GOOGLE_DOCS_TAB',
                    'tab_id' => $tab['tab_id'],
                    'parent_tab_id' => null,
                    'nesting_level' => 0,
                    'index' => $index,
                ],
            ];
            $plan->sourcePassages()->create([
                'source_sha256' => $plan->source_sha256,
                'source_key' => 'google-tab:'.$tab['tab_id'].':paragraph:1',
                'sequence' => $index + 1,
                'kind' => 'paragraph',
                'text' => $text,
                'metadata' => ['tab_id' => $tab['tab_id'], 'source_context' => $context, 'source_group' => $context],
                'content_sha256' => hash('sha256', $text),
            ]);
            $status = $index === 0
                ? WeeklyPlanGoogleDocsMappingStatus::VERIFIED
                : WeeklyPlanGoogleDocsMappingStatus::NOT_APPLICABLE;
            $mapping = WeeklyPlanGoogleDocsTabMapping::query()->create([
                'organization_id' => $organizationId,
                'weekly_plan_id' => $plan->id,
                'document_id_sha256' => $documentHash,
                'tab_id_sha256' => hash('sha256', $tab['tab_id']),
                'tab_title_sha256' => hash('sha256', mb_strtolower($tab['title'])),
                'status' => $status,
                'assignee_user_id' => $index === 0 ? $employee->id : null,
                'verified_by_user_id' => $creator->id,
                'verified_at' => now(),
                'version' => 1,
            ]);
            DB::table('weekly_plan_google_docs_tab_mapping_events')->insert([
                'mapping_id' => $mapping->id,
                'organization_id' => $organizationId,
                'weekly_plan_id' => $plan->id,
                'actor_user_id' => $creator->id,
                'previous_status' => null,
                'new_status' => $status->value,
                'previous_assignee_user_id' => null,
                'new_assignee_user_id' => $index === 0 ? $employee->id : null,
                'tab_title_sha256' => hash('sha256', mb_strtolower($tab['title'])),
                'mapping_version' => 1,
                'created_at' => now(),
            ]);
        }

        return $plan->fresh();
    }

    /** @return array<string, mixed> */
    private function manifestTab(string $id, string $title, int $index): array
    {
        return [
            'tab_id' => $id,
            'title' => $title,
            'parent_tab_id' => null,
            'nesting_level' => 0,
            'index' => $index,
            'order' => $index + 1,
            'passage_count' => 1,
            'character_count' => 20,
            'source_group' => [],
        ];
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
