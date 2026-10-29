<?php

namespace Tests\Feature;

use App\Enums\WeeklyPlanParseStatus;
use App\Enums\WeeklyPlanSourceType;
use App\Enums\WeeklyPlanStatus;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Services\WeeklyPlanning\Contracts\WeeklyPlanTaskExtractor;
use App\Services\WeeklyPlanning\WeeklyPlanTaskExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningAiResetCycleTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    public function test_reset_events_create_stable_and_distinct_ai_idempotency_cycles(): void
    {
        $actor = User::factory()->create();
        $membership = $this->createMembership($actor);
        $plan = WeeklyPlan::query()->create([
            'organization_id' => $membership['organization_id'],
            'created_by' => $actor->id,
            'week_start' => '2026-11-02',
            'week_end' => '2026-11-07',
            'status' => WeeklyPlanStatus::DRAFT,
            'source_type' => WeeklyPlanSourceType::UPLOADED_FILE,
            'original_filename' => 'plan.docx',
            'source_extension' => 'docx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'source_size' => 100,
            'source_sha256' => hash('sha256', 'unchanged-source'),
            'parse_status' => WeeklyPlanParseStatus::PARSED,
            'uploaded_at' => now(),
        ]);
        $passage = $plan->sourcePassages()->create([
            'source_sha256' => $plan->source_sha256,
            'source_key' => 'docx-paragraph:1',
            'sequence' => 1,
            'kind' => 'paragraph',
            'text' => 'Prepare the weekly plan.',
            'metadata' => [],
            'content_sha256' => hash('sha256', 'Prepare the weekly plan.'),
        ]);
        $plan->load('sourcePassages');
        $extractor = new CapturingWeeklyPlanTaskExtractor;
        $service = new WeeklyPlanTaskExtractionService($extractor);

        $service->preview($plan, $actor);
        $legacyKey = $extractor->keys[0];
        $service->preview($plan, $actor);
        $this->assertSame($legacyKey, $extractor->keys[1]);

        $legacyContext = $extractor->contexts[0];
        unset($legacyContext['_request']);
        $expectedLegacyKey = hash('sha256', implode('|', [
            'weekly-plan-task-extraction-v1',
            (string) $plan->id,
            $plan->source_sha256,
            hash('sha256', json_encode($legacyContext, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'DOCX',
        ]));
        $this->assertSame($expectedLegacyKey, $legacyKey);

        $firstResetId = DB::table('weekly_plan_events')->insertGetId([
            'weekly_plan_id' => $plan->id,
            'actor_id' => $actor->id,
            'event_type' => 'PLAN_RESET',
            'metadata' => json_encode(['entry_count' => 0], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
        $service->preview($plan, $actor);
        $firstResetKey = $extractor->keys[2];
        $service->preview($plan, $actor);
        $this->assertNotSame($legacyKey, $firstResetKey);
        $this->assertSame($firstResetKey, $extractor->keys[3]);
        $this->assertSame(
            hash('sha256', implode('|', [
                'weekly-plan-task-extraction-v1',
                (string) $plan->id,
                $plan->source_sha256,
                hash('sha256', json_encode($legacyContext, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
                'DOCX',
                'reset-cycle:'.$firstResetId,
            ])),
            $firstResetKey,
        );

        DB::table('weekly_plan_events')->insert([
            'weekly_plan_id' => $plan->id,
            'actor_id' => $actor->id,
            'event_type' => 'PLAN_RESET',
            'metadata' => json_encode(['entry_count' => 0], JSON_THROW_ON_ERROR),
            'created_at' => now()->addSecond(),
        ]);
        $service->preview($plan, $actor);
        $this->assertNotSame($firstResetKey, $extractor->keys[4]);
        $this->assertSame(
            $passage->source_key,
            $extractor->contexts[4]['passages'][0]['source_key'],
        );
    }
}

class CapturingWeeklyPlanTaskExtractor implements WeeklyPlanTaskExtractor
{
    /** @var list<string> */
    public array $keys = [];

    /** @var list<array<string, mixed>> */
    public array $contexts = [];

    public function isConfigured(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'capturing-test';
    }

    public function extract(array $context): array
    {
        $this->keys[] = $context['_request']['idempotency_key'];
        $this->contexts[] = $context;

        return [[
            'source_keys' => [$context['passages'][0]['source_key']],
            'title' => 'Prepare weekly plan',
            'content' => 'Prepare and review the weekly plan.',
            'expected_result' => 'Plan completed.',
            'employee_identifier' => 'Employee A',
            'start_date' => $context['week_start'],
            'due_date' => $context['week_end'],
            'priority' => 'MEDIUM',
            'note' => null,
            'verification_fields' => ['assignee_id'],
            'evidence' => 'Prepare the weekly plan.',
            'provenance' => [
                'title' => 'VERBATIM',
                'content' => 'INFERRED',
                'expected_result' => 'SUGGESTED',
                'employee_identifier' => 'VERBATIM',
                'start_date' => 'SUGGESTED',
                'due_date' => 'SUGGESTED',
                'priority' => 'SUGGESTED',
                'note' => 'SUGGESTED',
            ],
        ]];
    }
}
