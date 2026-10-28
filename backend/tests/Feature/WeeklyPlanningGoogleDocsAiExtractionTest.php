<?php

namespace Tests\Feature;

use App\Enums\DataScope;
use App\Enums\WeeklyPlanGoogleDocsMappingStatus;
use App\Enums\WeeklyPlanSourceType;
use App\Enums\WeeklyPlanStatus;
use App\Models\OrganizationGoogleDocsFolder;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanGoogleDocsSource;
use App\Models\WeeklyPlanGoogleDocsTabMapping;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningGoogleDocsAiExtractionTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config()->set([
            'weekly_plans.document_ai_driver' => 'openai',
            'weekly_plans.ai_max_passages' => 20,
            'weekly_plans.ai_max_context_bytes' => 20000,
            'weekly_plans.ai_max_tasks_per_response' => 20,
            'weekly_plans.ai_max_output_tokens' => 6000,
            'weekly_plans.ai_reasoning_effort' => 'low',
            'kps_ai.api_key' => 'test-openai-key',
            'kps_ai.model' => 'gpt-6-luna',
            'kps_ai.base_url' => 'https://api.openai.test/v1',
            'kps_ai.monthly_budget_usd' => 10,
            'kps_ai.input_usd_per_million_tokens' => 1,
            'kps_ai.output_usd_per_million_tokens' => 2,
            'kps_ai.max_input_bytes' => 30000,
            'kps_ai.max_input_tokens' => 30000,
            'kps_ai.max_output_tokens' => 6000,
            'kps_ai.max_attempts' => 1,
            'kps_ai.retry_delay_ms' => 0,
            'kps_ai.max_retry_delay_ms' => 0,
        ]);
    }

    public function test_unverified_and_needs_review_tabs_block_ai_before_provider_access(): void
    {
        [$hr, $organizationId] = $this->manager();
        $unverified = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', null),
        ], '2026-11-02');
        $needsReview = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-b', 'Tab B', WeeklyPlanGoogleDocsMappingStatus::NEEDS_REVIEW),
        ], '2026-11-09');
        Http::fake();

        foreach ([$unverified, $needsReview] as $plan) {
            $this->actingAs($hr)
                ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract")
                ->assertConflict()
                ->assertJsonPath('code', 'GOOGLE_DOCS_MAPPING_INCOMPLETE');
            $this->actingAs($hr)
                ->getJson("/api/weekly-plans/{$plan->id}")
                ->assertOk()
                ->assertJsonPath('data.actions.can_ai_extract_source', false);
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_usage_records', 0);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
    }

    public function test_scoped_multi_tab_extraction_uses_only_selected_verified_passages_in_one_request(): void
    {
        [$hr, $organizationId] = $this->manager();
        $firstEmployee = $this->employee($organizationId, 'Employee A');
        $secondEmployee = $this->employee($organizationId, 'Employee B');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $firstEmployee->id),
            $this->tab('tab-b', 'Tab B', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $secondEmployee->id),
            $this->tab('tab-c', 'Unresolved Tab', WeeklyPlanGoogleDocsMappingStatus::NEEDS_REVIEW),
        ]);
        $passages = $plan->sourcePassages()->orderBy('sequence')->get();
        Http::fake([
            'api.openai.test/v1/responses' => Http::response($this->completedResponse([
                'tasks' => [
                    $this->validSuggestion($passages[0]->source_key),
                    $this->validSuggestion($passages[1]->source_key),
                ],
            ])),
        ]);

        $this->actingAs($hr)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_ai_extract_source', false)
            ->assertJsonPath('data.actions.can_ai_extract_selected_source', true);

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract", [
                'source_passage_ids' => [$passages[0]->id, $passages[1]->id],
            ])
            ->assertOk()
            ->assertJsonCount(2, 'data.suggestions')
            ->assertJsonPath('data.suggestions.0.assignee.id', $firstEmployee->id)
            ->assertJsonPath('data.suggestions.1.assignee.id', $secondEmployee->id);

        Http::assertSentCount(1);
        $providerRequest = Http::recorded()->first()[0];
        $input = json_decode(
            (string) data_get($providerRequest->data(), 'input.0.content.0.text'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $serialized = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $this->assertSame(
            [$passages[0]->source_key, $passages[1]->source_key],
            collect($input['passages'])->pluck('source_key')->all(),
        );
        $this->assertSame(['tab-a', 'tab-b'], $input['selected_tab_ids']);
        $this->assertStringNotContainsString($passages[2]->source_key, $serialized);
        $this->assertStringNotContainsString($firstEmployee->email, $serialized);
        $this->assertStringNotContainsString($secondEmployee->email, $serialized);
        $this->assertStringNotContainsString('assignee', $serialized);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
    }

    public function test_google_docs_detail_suggestions_are_strict_bounded_and_keep_assignment_server_trusted(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Employee A');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
        ]);
        $passage = $plan->sourcePassages()->firstOrFail();
        $suggestion = $this->validSuggestion($passage->source_key);
        $suggestion['start_date'] = 'not-a-date';
        $suggestion['due_date'] = '2099-12-31';
        $suggestion['verification_fields'] = ['assignee_id', 'start_date', 'due_date'];
        $suggestion['detail_suggestions'] = $this->detailSuggestions();
        Http::fake([
            'api.openai.test/v1/responses' => Http::response($this->completedResponse([
                'tasks' => [$suggestion],
            ])),
        ]);

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract")
            ->assertOk()
            ->assertJsonPath('data.suggestions.0.detail_suggestions.channel.value', 'TikTok')
            ->assertJsonPath('data.suggestions.0.detail_suggestions.channel.basis', 'SOURCE_DERIVED')
            ->assertJsonPath('data.suggestions.0.detail_suggestions.frequency.basis', 'AI_SUGGESTION')
            ->assertJsonPath('data.suggestions.0.start_date', '2026-11-02')
            ->assertJsonPath('data.suggestions.0.due_date', '2026-11-07')
            ->assertJsonPath('data.suggestions.0.verification_fields', [])
            ->assertJsonPath('data.suggestions.0.assignee.id', $employee->id)
            ->assertJsonPath('data.suggestions.0.assignment_source', 'VERIFIED_GOOGLE_DOCS_TAB_MAPPING')
            ->assertJsonPath('data.suggestions.0.evidence', 'Content can be verified.')
            ->assertJsonPath('data.suggestions.0.provenance.title', 'VERBATIM');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $schema = data_get($payload, 'text.format.schema.properties.tab_results.items.properties.tasks.items.properties.detail_suggestions');

            $instructions = (string) ($payload['instructions'] ?? '');

            return data_get($payload, 'text.format.name') === 'kps_weekly_plan_google_docs_tab_results_v4'
                && data_get($payload, 'text.format.strict') === true
                && data_get($payload, 'text.format.schema.additionalProperties') === false
                && data_get($payload, 'text.format.schema.properties.tab_results.minItems') === 1
                && data_get($payload, 'text.format.schema.properties.tab_results.maxItems') === 1
                && data_get($payload, 'text.format.schema.properties.tab_results.items.properties.tab_id.enum') === ['tab-a']
                && data_get($payload, 'text.format.schema.properties.tab_results.items.additionalProperties') === false
                && data_get($payload, 'text.format.schema.properties.tab_results.items.properties.status.enum') === ['TASKS_PROPOSED']
                && data_get($payload, 'text.format.schema.properties.tab_results.items.properties.tasks.minItems') === 1
                && data_get($payload, 'text.format.schema.properties.tab_results.items.properties.tasks.maxItems') === 20
                && data_get($schema, 'anyOf.0.additionalProperties') === false
                && data_get($schema, 'anyOf.0.properties.channel.anyOf.0.properties.value.minLength') === 1
                && data_get($schema, 'anyOf.0.properties.channel.anyOf.0.properties.value.maxLength') === 500
                && data_get($schema, 'anyOf.0.properties.channel.anyOf.0.properties.basis.enum')
                    === ['SOURCE_DERIVED', 'AI_SUGGESTION']
                && str_contains($instructions, 'you may use general operational reasoning')
                && str_contains($instructions, 'Use SOURCE_DERIVED only when the exact detail is explicitly supported')
                && str_contains($instructions, 'Every unsupported operational recommendation must use basis=AI_SUGGESTION')
                && str_contains($instructions, 'must never be represented as a source or company fact')
                && str_contains($instructions, 'must not invent employee identity, KPS account ownership')
                && str_contains($instructions, 'credentials, identifiers, or dates outside week_start and week_end')
                && str_contains($instructions, 'return every selected tab_id exactly once')
                && str_contains($instructions, 'Every selected tab requires at least one actionable proposed job')
                && str_contains($instructions, 'must never decide or change mapping applicability')
                && str_contains($instructions, 'HR must classify a tab as NOT_APPLICABLE before AI extraction')
                && str_contains($instructions, 'at most 20 proposed jobs in total across all selected tabs')
                && str_contains($instructions, 'never silently omit a selected tab')
                && ! str_contains($instructions, 'không dùng kiến thức bên ngoài và không tạo dữ kiện');
        });
        $this->assertDatabaseCount('weekly_plan_entries', 0);
    }

    public function test_missing_detail_suggestions_are_safe_and_malformed_detail_suggestions_are_rejected(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Employee A');
        $validPlan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
        ], '2026-11-02');
        $invalidPlan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-b', 'Tab B', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
        ], '2026-11-09');
        $missing = $this->validSuggestion($validPlan->sourcePassages()->firstOrFail()->source_key);
        $malformed = $this->validSuggestion($invalidPlan->sourcePassages()->firstOrFail()->source_key);
        $malformed['start_date'] = '2026-11-09';
        $malformed['due_date'] = '2026-11-14';
        $malformed['detail_suggestions'] = $this->detailSuggestions();
        $malformed['detail_suggestions']['channel']['basis'] = 'UNSUPPORTED_FACT';
        Http::fake([
            'api.openai.test/v1/responses' => Http::sequence()
                ->push($this->completedResponse(['tasks' => [$missing]]))
                ->push($this->completedResponse(['tasks' => [$malformed]])),
        ]);

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$validPlan->id}/source/ai-extract")
            ->assertOk()
            ->assertJsonPath('data.suggestions.0.detail_suggestions', null);
        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$invalidPlan->id}/source/ai-extract")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('extraction');

        $this->assertDatabaseHas('ai_usage_records', [
            'organization_id' => $organizationId,
            'feature' => 'weekly_plan_task_extraction',
            'status' => 'rejected',
            'error_code' => 'AI_RESULT_REJECTED',
        ]);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
    }

    public function test_apply_for_a_verified_selected_tab_does_not_require_unrelated_tabs_to_be_resolved(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Employee A');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Verified', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
            $this->tab('tab-b', 'Unresolved', WeeklyPlanGoogleDocsMappingStatus::NEEDS_REVIEW),
        ]);
        $passage = $plan->sourcePassages()->orderBy('sequence')->firstOrFail();

        $this->apply(
            $hr,
            $plan,
            $passage->id,
            $employee->id,
            '12121212-1212-4121-8121-121212121212',
        )->assertOk();

        $this->assertDatabaseHas('weekly_plan_entries', [
            'weekly_plan_id' => $plan->id,
            'assignee_id' => $employee->id,
            'start_date' => $plan->week_start->toDateString(),
            'due_date' => $plan->week_end->toDateString(),
            'priority' => 'MEDIUM',
        ]);
    }

    public function test_scoped_extraction_rejects_unknown_and_cross_plan_passages(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Employee A');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
        ], '2026-11-02');
        $otherPlan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-b', 'Tab B', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
        ], '2026-11-09');
        Http::fake();

        foreach ([
            [999999],
            [$otherPlan->sourcePassages()->firstOrFail()->id],
        ] as $sourcePassageIds) {
            $this->actingAs($hr)
                ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract", [
                    'source_passage_ids' => $sourcePassageIds,
                ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('source_passage_ids');
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_usage_records', 0);
    }

    public function test_scoped_extraction_rejects_a_passage_from_an_old_source_snapshot(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Employee A');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
        ]);
        $oldSnapshotPassage = $plan->sourcePassages()->create([
            'source_sha256' => hash('sha256', 'old-snapshot'),
            'source_key' => 'google-tab:old:paragraph:1',
            'sequence' => 99,
            'kind' => 'paragraph',
            'text' => 'Old snapshot',
            'metadata' => [],
            'content_sha256' => hash('sha256', 'Old snapshot'),
        ]);
        Http::fake();

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract", [
                'source_passage_ids' => [$oldSnapshotPassage->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_passage_ids');

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_usage_records', 0);
    }

    public function test_scoped_extraction_rejects_unresolved_and_not_applicable_tabs(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Employee A');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Verified', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
            $this->tab('tab-b', 'Needs review', WeeklyPlanGoogleDocsMappingStatus::NEEDS_REVIEW),
            $this->tab('tab-c', 'Not applicable', WeeklyPlanGoogleDocsMappingStatus::NOT_APPLICABLE),
        ]);
        $passages = $plan->sourcePassages()->orderBy('sequence')->get();
        Http::fake();

        foreach ([$passages[1]->id, $passages[2]->id] as $passageId) {
            $this->actingAs($hr)
                ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract", [
                    'source_passage_ids' => [$passageId],
                ])
                ->assertConflict()
                ->assertJsonPath('code', 'GOOGLE_DOCS_SELECTED_TAB_NOT_VERIFIED');
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_usage_records', 0);
    }

    public function test_selected_scope_changes_the_ai_idempotency_fingerprint(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Employee A');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
            $this->tab('tab-b', 'Tab B', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
        ]);
        $passages = $plan->sourcePassages()->orderBy('sequence')->get();
        Http::fake([
            'api.openai.test/v1/responses' => Http::sequence()
                ->push($this->completedResponse(['tasks' => [$this->validSuggestion($passages[0]->source_key)]]))
                ->push($this->completedResponse(['tasks' => [$this->validSuggestion($passages[1]->source_key)]])),
        ]);

        foreach ($passages as $passage) {
            $this->actingAs($hr)
                ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract", [
                    'source_passage_ids' => [$passage->id],
                ])
                ->assertOk();
        }

        Http::assertSentCount(2);
        $fingerprints = DB::table('ai_usage_records')
            ->where('feature', 'weekly_plan_task_extraction')
            ->pluck('request_fingerprint');
        $this->assertCount(2, $fingerprints);
        $this->assertCount(2, $fingerprints->unique());
    }

    public function test_one_request_excludes_not_applicable_tabs_and_enriches_from_verified_mapping(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Employee A');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
            $this->tab('tab-b', 'Not applicable', WeeklyPlanGoogleDocsMappingStatus::NOT_APPLICABLE),
        ]);
        $passages = $plan->sourcePassages()->orderBy('sequence')->get();
        $task = $this->validSuggestion($passages[0]->source_key);
        $task['employee_identifier'] = 'AI invented employee';
        $task['verification_fields'] = ['assignee_id', 'title'];
        Http::fake([
            'api.openai.test/v1/responses' => Http::response($this->completedResponse([
                'tasks' => [$task],
            ])),
        ]);

        $response = $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract")
            ->assertOk()
            ->assertJsonCount(1, 'data.suggestions')
            ->assertJsonPath('data.suggestions.0.employee_identifier', null)
            ->assertJsonPath('data.suggestions.0.source_tab.tab_id', 'tab-a')
            ->assertJsonPath('data.suggestions.0.source_tab.title', 'Tab A')
            ->assertJsonPath('data.suggestions.0.assignee.id', $employee->id)
            ->assertJsonPath('data.suggestions.0.assignee.name', $employee->name)
            ->assertJsonPath('data.suggestions.0.assignee.email', $employee->email)
            ->assertJsonPath('data.suggestions.0.assignment_source', 'VERIFIED_GOOGLE_DOCS_TAB_MAPPING')
            ->assertJsonPath('data.suggestions.0.verification_fields.0', 'title');

        $this->actingAs($hr)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.actions.can_ai_extract_source', true)
            ->assertJsonPath('data.source_document.google_docs.processed_tab_ids', [])
            ->assertJsonPath('data.source_document.google_docs.remaining_tab_ids', ['tab-a'])
            ->assertJsonPath('data.source_document.google_docs.processed_tab_count', 0)
            ->assertJsonPath('data.source_document.google_docs.relevant_tab_count', 1);
        Http::assertSentCount(1);
        $providerRequest = Http::recorded()->first()[0];
        $payload = $providerRequest->data();
        $input = json_decode(
            (string) data_get($payload, 'input.0.content.0.text'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $serialized = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $values = [];
        array_walk_recursive($input, function (mixed $value) use (&$values): void {
            $values[] = $value;
        });
        $this->assertSame('GOOGLE_DOCS', data_get($input, 'source_type'));
        $this->assertCount(1, $input['passages']);
        $this->assertSame($passages[0]->source_key, data_get($input, 'passages.0.source_key'));
        $this->assertSame('tab-a', data_get($input, 'passages.0.tab_id'));
        $this->assertSame(data_get($passages[0]->metadata, 'tab_title'), data_get($input, 'passages.0.tab_title'));
        $this->assertStringNotContainsString('"assignee', $serialized);
        $this->assertFalse(in_array($employee->id, $values, true));
        $this->assertStringNotContainsString($employee->email, $serialized);
        $this->assertStringNotContainsString((string) data_get($passages[1]->metadata, 'tab_title'), $serialized);
        $this->assertStringContainsString('GOOGLE_DOCS', (string) ($payload['instructions'] ?? ''));
        $this->assertStringContainsString('employee_identifier', (string) ($payload['instructions'] ?? ''));
        $this->assertSame('title', $response->json('data.suggestions.0.verification_fields.0'));
        $this->assertDatabaseCount('weekly_plan_entries', 0);
    }

    public function test_no_scope_extraction_still_analyzes_every_eligible_verified_tab(): void
    {
        [$hr, $organizationId] = $this->manager();
        $firstEmployee = $this->employee($organizationId, 'Employee A');
        $secondEmployee = $this->employee($organizationId, 'Employee B');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $firstEmployee->id),
            $this->tab('tab-b', 'Tab B', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $secondEmployee->id),
            $this->tab('tab-c', 'Not applicable', WeeklyPlanGoogleDocsMappingStatus::NOT_APPLICABLE),
        ]);
        $passages = $plan->sourcePassages()->orderBy('sequence')->get();
        Http::fake([
            'api.openai.test/v1/responses' => Http::response($this->completedResponse([
                'tasks' => [
                    $this->validSuggestion($passages[0]->source_key),
                    $this->validSuggestion($passages[1]->source_key),
                ],
            ])),
        ]);

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract")
            ->assertOk()
            ->assertJsonCount(2, 'data.suggestions');

        Http::assertSentCount(1);
        $input = json_decode(
            (string) data_get(Http::recorded()->first()[0]->data(), 'input.0.content.0.text'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(
            [$passages[0]->source_key, $passages[1]->source_key],
            collect($input['passages'])->pluck('source_key')->all(),
        );
    }

    public function test_cross_tab_and_hallucinated_source_keys_are_rejected_and_usage_is_marked_rejected(): void
    {
        [$hr, $organizationId] = $this->manager();
        $first = $this->employee($organizationId, 'Employee A');
        $second = $this->employee($organizationId, 'Employee B');
        $mixedPlan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $first->id),
            $this->tab('tab-b', 'Tab B', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $second->id),
        ], '2026-11-02');
        $unknownPlan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-c', 'Tab C', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $first->id),
        ], '2026-11-09');
        $keys = $mixedPlan->sourcePassages()->orderBy('sequence')->pluck('source_key')->all();
        $mixedTask = $this->validSuggestion($keys[0]);
        $mixedTask['source_keys'] = $keys;
        Http::fake([
            'api.openai.test/v1/responses' => Http::sequence()
                ->push($this->completedResponse(['tasks' => [$mixedTask]]))
                ->push($this->completedResponse(['tasks' => [$this->validSuggestion('invented:key')]])),
        ]);

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$mixedPlan->id}/source/ai-extract")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('extraction');
        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$unknownPlan->id}/source/ai-extract")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('extraction');

        $this->assertSame(2, DB::table('ai_usage_records')->where('status', 'rejected')->count());
        $this->assertDatabaseCount('weekly_plan_entries', 0);
    }

    public function test_mapping_change_after_preview_rejects_old_assignee_and_accepts_current_assignee(): void
    {
        [$hr, $organizationId] = $this->manager();
        $oldEmployee = $this->employee($organizationId, 'Old employee');
        $newEmployee = $this->employee($organizationId, 'New employee');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $oldEmployee->id),
        ]);
        $passage = $plan->sourcePassages()->firstOrFail();
        Http::fake([
            'api.openai.test/v1/responses' => Http::response($this->completedResponse([
                'tasks' => [$this->validSuggestion($passage->source_key)],
            ])),
        ]);
        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract")
            ->assertOk()
            ->assertJsonPath('data.suggestions.0.assignee.id', $oldEmployee->id);

        WeeklyPlanGoogleDocsTabMapping::query()
            ->where('weekly_plan_id', $plan->id)
            ->update(['assignee_user_id' => $newEmployee->id, 'version' => 2]);

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract")
            ->assertOk()
            ->assertJsonPath('data.suggestions.0.assignee.id', $newEmployee->id);
        Http::assertSentCount(2);

        $this->apply($hr, $plan, $passage->id, $oldEmployee->id, 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entries.0.assignee_id');
        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->apply($hr, $plan, $passage->id, $newEmployee->id, 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb')
            ->assertOk();
        $this->assertDatabaseHas('weekly_plan_entries', [
            'weekly_plan_id' => $plan->id,
            'assignee_id' => $newEmployee->id,
        ]);
    }

    public function test_mapping_change_during_provider_request_rejects_the_stale_preview(): void
    {
        [$hr, $organizationId] = $this->manager();
        $oldEmployee = $this->employee($organizationId, 'Old employee');
        $newEmployee = $this->employee($organizationId, 'New employee');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $oldEmployee->id),
        ]);
        $passage = $plan->sourcePassages()->firstOrFail();
        Http::fake(function () use ($newEmployee, $passage, $plan) {
            WeeklyPlanGoogleDocsTabMapping::query()
                ->where('weekly_plan_id', $plan->id)
                ->update(['assignee_user_id' => $newEmployee->id, 'version' => 2]);

            return Http::response($this->completedResponse([
                'tasks' => [$this->validSuggestion($passage->source_key)],
            ]));
        });

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract")
            ->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_MAPPING_CHANGED');

        Http::assertSentCount(1);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
    }

    public function test_inactive_mapping_employee_blocks_preview_and_apply_without_writes(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Inactive employee');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
        ]);
        $passage = $plan->sourcePassages()->firstOrFail();
        OrganizationMembership::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $employee->id)
            ->update(['is_active' => false]);
        Http::fake();

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract")
            ->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_MAPPING_INCOMPLETE');
        $this->apply($hr, $plan, $passage->id, $employee->id, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entries');

        Http::assertNothingSent();
        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('weekly_plan_entry_applications', 0);
    }

    public function test_apply_rejects_inconsistent_persisted_tab_metadata(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Employee A');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
        ]);
        $passage = $plan->sourcePassages()->firstOrFail();
        $metadata = $passage->metadata;
        data_set($metadata, 'source_context.evidence.tab_id', 'different-tab');
        $passage->forceFill(['metadata' => $metadata])->save();

        $this->apply($hr, $plan, $passage->id, $employee->id, 'ffffffff-ffff-4fff-8fff-ffffffffffff')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('entries.0.source_passage_ids');

        $this->assertDatabaseCount('weekly_plan_entries', 0);
        $this->assertDatabaseCount('weekly_plan_entry_applications', 0);
    }

    public function test_authorization_and_organization_boundaries_fail_closed_before_provider_access(): void
    {
        [$hr, $organizationId] = $this->manager();
        [$outsider, $otherOrganizationId] = $this->manager('OTHER_AI_ORG');
        $foreignEmployee = $this->employee($otherOrganizationId, 'Foreign employee');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $foreignEmployee->id),
        ]);
        Http::fake();

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract")
            ->assertConflict()
            ->assertJsonPath('code', 'GOOGLE_DOCS_MAPPING_INCOMPLETE');
        $this->actingAs($outsider)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract")
            ->assertUnauthorized();

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_usage_records', 0);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
    }

    public function test_multiple_entries_apply_atomically_and_one_invalid_assignment_rolls_back_all(): void
    {
        [$hr, $organizationId] = $this->manager();
        $first = $this->employee($organizationId, 'Employee A');
        $second = $this->employee($organizationId, 'Employee B');
        $validPlan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $first->id),
            $this->tab('tab-b', 'Tab B', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $second->id),
        ], '2026-11-02');
        $invalidPlan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-c', 'Tab C', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $first->id),
            $this->tab('tab-d', 'Tab D', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $second->id),
        ], '2026-11-09');

        $validPassages = $validPlan->sourcePassages()->orderBy('sequence')->get();
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$validPlan->id}/source/apply", [
            'request_id' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
            'entries' => [
                $this->entry($validPassages[0]->id, $first->id, 'Task A', '2026-11-02'),
                $this->entry($validPassages[1]->id, $second->id, 'Task B', '2026-11-02'),
            ],
        ])->assertOk();
        $this->assertSame(2, $validPlan->entries()->count());

        $invalidPassages = $invalidPlan->sourcePassages()->orderBy('sequence')->get();
        $this->actingAs($hr)->postJson("/api/weekly-plans/{$invalidPlan->id}/source/apply", [
            'request_id' => 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee',
            'entries' => [
                $this->entry($invalidPassages[0]->id, $first->id, 'Task C', '2026-11-09'),
                $this->entry($invalidPassages[1]->id, $first->id, 'Wrong employee', '2026-11-09'),
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('entries.1.assignee_id');
        $this->assertSame(0, $invalidPlan->entries()->count());
        $this->assertDatabaseMissing('weekly_plan_entry_applications', [
            'weekly_plan_id' => $invalidPlan->id,
        ]);
    }

    public function test_applied_tabs_are_derived_as_processed_across_reload_and_cannot_be_reprocessed(): void
    {
        [$hr, $organizationId] = $this->manager();
        $first = $this->employee($organizationId, 'Employee A');
        $second = $this->employee($organizationId, 'Employee B');
        $third = $this->employee($organizationId, 'Employee C');
        $fourth = $this->employee($organizationId, 'Employee D');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $first->id),
            $this->tab('tab-b', 'Tab B', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $second->id),
            $this->tab('tab-c', 'Tab C', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $third->id),
            $this->tab('tab-d', 'Tab D', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $fourth->id),
        ]);
        $passages = $plan->sourcePassages()->orderBy('sequence')->get();

        $firstApply = $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/source/apply", [
            'request_id' => '11111111-2222-4333-8444-555555555555',
            'entries' => [
                $this->entry($passages[0]->id, $first->id, 'Task A', '2026-11-02'),
                $this->entry($passages[1]->id, $second->id, 'Task B', '2026-11-02'),
            ],
        ])->assertOk()
            ->assertJsonPath('data.source_document.google_docs.processed_tab_ids', ['tab-a', 'tab-b'])
            ->assertJsonPath('data.source_document.google_docs.remaining_tab_ids', ['tab-c', 'tab-d'])
            ->assertJsonPath('data.source_document.google_docs.processed_tab_count', 2)
            ->assertJsonPath('data.source_document.google_docs.relevant_tab_count', 4)
            ->assertJsonCount(2, 'data.entries');
        $firstEntryIds = collect($firstApply->json('data.entries'))->pluck('id')->all();

        $this->actingAs($hr)
            ->getJson("/api/weekly-plans/{$plan->id}")
            ->assertOk()
            ->assertJsonPath('data.source_document.google_docs.processed_tab_ids', ['tab-a', 'tab-b'])
            ->assertJsonPath('data.source_document.google_docs.remaining_tab_ids', ['tab-c', 'tab-d'])
            ->assertJsonCount(2, 'data.entries');

        Http::fake();
        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract", [
                'source_passage_ids' => [$passages[0]->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_passage_ids');
        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract", [
                'source_passage_ids' => [$passages[0]->id, $passages[2]->id],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('source_passage_ids');
        $this->apply(
            $hr,
            $plan,
            $passages[0]->id,
            $first->id,
            '12121212-3434-4567-8989-121212121212',
        )->assertUnprocessable()
            ->assertJsonValidationErrors('entries');
        Http::assertNothingSent();
        $this->assertDatabaseCount('weekly_plan_entries', 2);

        $this->actingAs($hr)->postJson("/api/weekly-plans/{$plan->id}/source/apply", [
            'request_id' => '66666666-7777-4888-8999-000000000000',
            'entries' => [
                $this->entry($passages[2]->id, $third->id, 'Task C', '2026-11-02'),
                $this->entry($passages[3]->id, $fourth->id, 'Task D', '2026-11-02'),
            ],
        ])->assertOk()
            ->assertJsonPath('data.source_document.google_docs.processed_tab_ids', ['tab-a', 'tab-b', 'tab-c', 'tab-d'])
            ->assertJsonPath('data.source_document.google_docs.remaining_tab_ids', [])
            ->assertJsonPath('data.actions.can_apply_source', false)
            ->assertJsonPath('data.actions.can_ai_extract_selected_source', false)
            ->assertJsonPath('data.actions.can_manage_google_docs_mappings', false)
            ->assertJsonPath('data.summary.employee_count', 4)
            ->assertJsonCount(4, 'data.entries');

        $this->assertSame($firstEntryIds, $plan->entries()->orderBy('sort_order')->limit(2)->pluck('id')->all());
        $this->assertDatabaseCount('weekly_plan_entries', 4);
    }

    public function test_selected_tab_coverage_rejects_missing_unknown_duplicate_and_zero_task_results(): void
    {
        [$hr, $organizationId] = $this->manager();
        $first = $this->employee($organizationId, 'Employee A');
        $second = $this->employee($organizationId, 'Employee B');
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $first->id),
            $this->tab('tab-b', 'Tab B', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $second->id),
        ]);
        $passages = $plan->sourcePassages()->orderBy('sequence')->get();
        $taskA = $this->validSuggestion($passages[0]->source_key);
        $taskB = $this->validSuggestion($passages[1]->source_key);
        Http::fake([
            'api.openai.test/v1/responses' => Http::sequence()
                ->push($this->completedResponse(['tab_results' => [
                    $this->tabResult('tab-a', [$taskA]),
                ]]))
                ->push($this->completedResponse(['tab_results' => [
                    $this->tabResult('tab-a', [$taskA]),
                    $this->tabResult('tab-z', [$taskB]),
                ]]))
                ->push($this->completedResponse(['tab_results' => [
                    $this->tabResult('tab-a', [$taskA]),
                    $this->tabResult('tab-a', [$taskA]),
                ]]))
                ->push($this->completedResponse(['tab_results' => [
                    $this->tabResult('tab-a', []),
                    $this->tabResult('tab-b', [$taskB]),
                ]]))
                ->push($this->completedResponse(['tab_results' => [
                    $this->tabResult('tab-a', [$taskA]),
                    $this->tabResult('tab-b', [$taskB]),
                ]])),
        ]);
        $scope = ['source_passage_ids' => $passages->pluck('id')->all()];
        $actors = [$hr];
        for ($index = 0; $index < 4; $index++) {
            [$actor] = $this->manager();
            $actors[] = $actor;
        }

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $this->actingAs($actors[$attempt])
                ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract", $scope)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('extraction');
        }
        $this->actingAs($actors[4])
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract", $scope)
            ->assertOk()
            ->assertJsonCount(2, 'data.suggestions')
            ->assertJsonPath('data.suggestions.0.source_tab.tab_id', 'tab-a')
            ->assertJsonPath('data.suggestions.1.source_tab.tab_id', 'tab-b');

        $this->assertDatabaseCount('weekly_plan_entries', 0);
    }

    public function test_google_docs_tab_results_keep_one_global_task_limit(): void
    {
        [$hr, $organizationId] = $this->manager();
        $employee = $this->employee($organizationId, 'Employee A');
        config()->set([
            'weekly_plans.ai_max_tasks_per_response' => 2,
            'weekly_plans.max_source_entries_per_request' => 2,
        ]);
        $plan = $this->googlePlan($organizationId, $hr, [
            $this->tab('tab-a', 'Tab A', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
            $this->tab('tab-b', 'Tab B', WeeklyPlanGoogleDocsMappingStatus::VERIFIED, $employee->id),
        ]);
        $passages = $plan->sourcePassages()->orderBy('sequence')->get();
        Http::fake([
            'api.openai.test/v1/responses' => Http::response($this->completedResponse(['tab_results' => [
                $this->tabResult('tab-a', [
                    $this->validSuggestion($passages[0]->source_key),
                    $this->validSuggestion($passages[0]->source_key),
                ]),
                $this->tabResult('tab-b', [
                    $this->validSuggestion($passages[1]->source_key),
                ]),
            ]])),
        ]);

        $this->actingAs($hr)
            ->postJson("/api/weekly-plans/{$plan->id}/source/ai-extract", [
                'source_passage_ids' => $passages->pluck('id')->all(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('extraction');

        $this->assertDatabaseHas('ai_usage_records', [
            'status' => 'rejected',
            'error_code' => 'AI_RESULT_REJECTED',
        ]);
        $this->assertDatabaseCount('weekly_plan_entries', 0);
    }

    /** @return array{User, int} */
    private function manager(string $organizationCode = 'KPS'): array
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user, organizationCode: $organizationCode);
        $roleId = $this->createRole();
        $this->attachRole($membership['membership_id'], $roleId, true);
        foreach (['weekly_plans.view', 'weekly_plans.manage_draft'] as $permission) {
            $this->grantPermission($roleId, $this->createPermission($permission), DataScope::ORGANIZATION->value);
        }

        return [$user, $membership['organization_id']];
    }

    private function employee(int $organizationId, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        OrganizationMembership::query()->create([
            'user_id' => $user->id,
            'organization_id' => $organizationId,
            'is_default' => true,
            'is_active' => true,
        ]);

        return $user;
    }

    /** @param list<array<string, mixed>> $tabs */
    private function googlePlan(int $organizationId, User $actor, array $tabs, string $weekStart = '2026-11-02'): WeeklyPlan
    {
        $folder = OrganizationGoogleDocsFolder::query()->firstOrCreate(
            ['organization_id' => $organizationId],
            [
                'google_folder_id' => 'folder-'.$organizationId,
                'is_active' => true,
                'approved_by_user_id' => $actor->id,
                'approved_at' => now(),
            ],
        );
        $documentId = 'document-'.$weekStart;
        $sourceHash = hash('sha256', $documentId.'|'.$weekStart);
        $manifest = collect($tabs)->map(fn (array $tab, int $index): array => [
            'tab_id' => $tab['tab_id'],
            'title' => $tab['title'],
            'parent_tab_id' => null,
            'nesting_level' => 0,
            'index' => $index,
            'order' => $index + 1,
            'passage_count' => 1,
            'character_count' => 20,
            'source_group' => [],
        ])->all();
        $plan = WeeklyPlan::query()->create([
            'organization_id' => $organizationId,
            'created_by' => $actor->id,
            'week_start' => $weekStart,
            'week_end' => date('Y-m-d', strtotime($weekStart.' +5 days')),
            'status' => WeeklyPlanStatus::DRAFT,
            'source_type' => WeeklyPlanSourceType::GOOGLE_DOCS,
            'original_filename' => 'Google Docs',
            'source_extension' => 'gdoc',
            'source_mime' => 'application/vnd.google-apps.document',
            'source_size' => 0,
            'source_sha256' => $sourceHash,
            'parse_status' => 'PARSED',
            'uploaded_at' => now(),
        ]);
        WeeklyPlanGoogleDocsSource::query()->create([
            'weekly_plan_id' => $plan->id,
            'organization_id' => $organizationId,
            'approved_folder_id' => $folder->id,
            'confirmed_by_user_id' => $actor->id,
            'request_id' => fake()->uuid(),
            'request_payload_sha256' => hash('sha256', fake()->uuid()),
            'google_document_id' => $documentId,
            'document_id_sha256' => hash('sha256', $documentId),
            'approved_folder_id_sha256' => hash('sha256', $folder->google_folder_id),
            'document_title' => 'Synthetic document',
            'revision_id' => null,
            'snapshot_sha256' => $sourceHash,
            'tab_manifest' => $manifest,
            'passage_count' => count($tabs),
            'character_count' => count($tabs) * 20,
            'captured_at' => now(),
            'last_verified_at' => now(),
        ]);

        foreach ($tabs as $index => $tab) {
            $text = 'Task content '.($index + 1);
            $context = [
                'key' => 'google-docs-tab:'.hash('sha256', $tab['tab_id']),
                'title' => $tab['title'],
                'origin' => 'GOOGLE_DOCS_TAB',
                'order' => $index + 1,
                'identity_verified' => false,
                'evidence' => [
                    'tab_id' => $tab['tab_id'],
                    'title' => $tab['title'],
                    'parent_tab_id' => null,
                    'nesting_level' => 0,
                ],
            ];
            $plan->sourcePassages()->create([
                'source_sha256' => $sourceHash,
                'source_key' => 'google-tab:'.$tab['tab_id'].':paragraph:1',
                'sequence' => $index + 1,
                'kind' => 'paragraph',
                'text' => $text,
                'metadata' => [
                    'tab_id' => $tab['tab_id'],
                    'tab_title' => $tab['title'],
                    'source_context' => $context,
                    'source_group' => $context,
                ],
                'content_sha256' => hash('sha256', $text),
            ]);
            if ($tab['status'] instanceof WeeklyPlanGoogleDocsMappingStatus) {
                WeeklyPlanGoogleDocsTabMapping::query()->create([
                    'organization_id' => $organizationId,
                    'weekly_plan_id' => $plan->id,
                    'document_id_sha256' => hash('sha256', $documentId),
                    'tab_id_sha256' => hash('sha256', $tab['tab_id']),
                    'tab_title_sha256' => hash('sha256', mb_strtolower($tab['title'])),
                    'status' => $tab['status'],
                    'assignee_user_id' => $tab['employee_id'],
                    'verified_by_user_id' => $actor->id,
                    'verified_at' => now(),
                    'version' => 1,
                ]);
            }
        }

        return $plan;
    }

    /** @return array<string, mixed> */
    private function tab(string $id, string $title, ?WeeklyPlanGoogleDocsMappingStatus $status, ?int $employeeId = null): array
    {
        return ['tab_id' => $id, 'title' => $title, 'status' => $status, 'employee_id' => $employeeId];
    }

    private function apply(User $actor, WeeklyPlan $plan, int $passageId, int $assigneeId, string $requestId)
    {
        return $this->actingAs($actor)->postJson("/api/weekly-plans/{$plan->id}/source/apply", [
            'request_id' => $requestId,
            'entries' => [$this->entry($passageId, $assigneeId, 'Task', $plan->week_start->toDateString())],
        ]);
    }

    /** @return array<string, mixed> */
    private function entry(int $passageId, int $assigneeId, string $title, string $weekStart): array
    {
        return [
            'source_passage_ids' => [$passageId],
            'assignee_id' => $assigneeId,
            'title' => $title,
            'content' => 'Content',
            'expected_result' => 'Expected result',
            'start_date' => $weekStart,
            'due_date' => date('Y-m-d', strtotime($weekStart.' +5 days')),
            'priority' => 'MEDIUM',
            'note' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function validSuggestion(string $sourceKey): array
    {
        return [
            'source_keys' => [$sourceKey],
            'title' => 'Complete plan',
            'content' => 'Prepare the plan.',
            'expected_result' => 'Plan completed.',
            'employee_identifier' => 'Untrusted identity',
            'start_date' => '2026-11-02',
            'due_date' => '2026-11-07',
            'priority' => 'MEDIUM',
            'note' => null,
            'verification_fields' => ['assignee_id'],
            'evidence' => 'Content can be verified.',
            'provenance' => [
                'title' => 'VERBATIM',
                'content' => 'INFERRED',
                'expected_result' => 'SUGGESTED',
                'employee_identifier' => 'SUGGESTED',
                'start_date' => 'SUGGESTED',
                'due_date' => 'SUGGESTED',
                'priority' => 'SUGGESTED',
                'note' => 'SUGGESTED',
            ],
        ];
    }

    /** @return array<string, array{value: string, basis: string}|null> */
    private function detailSuggestions(): array
    {
        return [
            'channel' => ['value' => 'TikTok', 'basis' => 'SOURCE_DERIVED'],
            'account' => null,
            'frequency' => ['value' => '1 video/day', 'basis' => 'AI_SUGGESTION'],
            'format' => ['value' => 'Vertical 9:16', 'basis' => 'AI_SUGGESTION'],
            'content_direction' => null,
            'objective' => null,
            'notes' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function completedResponse(array $result): array
    {
        if (array_keys($result) === ['tasks']) {
            $grouped = [];
            foreach ($result['tasks'] as $task) {
                $sourceKey = is_array($task) ? ($task['source_keys'][0] ?? '') : '';
                preg_match('/^google-tab:(.+):paragraph:/', (string) $sourceKey, $matches);
                $tabId = $matches[1] ?? 'tab-a';
                $grouped[$tabId][] = $task;
            }
            $result = [
                'tab_results' => collect($grouped)
                    ->map(fn (array $tasks, string $tabId): array => $this->tabResult($tabId, $tasks))
                    ->values()
                    ->all(),
            ];
        }

        return [
            'status' => 'completed',
            'output' => [[
                'type' => 'message',
                'content' => [[
                    'type' => 'output_text',
                    'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                ]],
            ]],
            'usage' => ['input_tokens' => 300, 'output_tokens' => 120],
        ];
    }

    /** @param list<array<string, mixed>> $tasks
     * @return array<string, mixed>
     */
    private function tabResult(
        string $tabId,
        array $tasks,
    ): array {
        return [
            'tab_id' => $tabId,
            'status' => 'TASKS_PROPOSED',
            'tasks' => $tasks,
        ];
    }
}
