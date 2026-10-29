<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_plan_review_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_plan_id');
            $table->string('stage', 32);
            $table->unsignedInteger('round_number');
            $table->string('status', 32);
            $table->foreignId('started_by')->nullable();
            $table->timestamp('started_at');
            $table->foreignId('completed_by')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['weekly_plan_id', 'stage', 'round_number'],
                'weekly_review_rounds_plan_stage_number_unique',
            );
            $table->index(
                ['weekly_plan_id', 'stage', 'status'],
                'weekly_review_rounds_plan_stage_status_index',
            );
            $table->index('started_by', 'weekly_review_rounds_started_by_index');
            $table->index('completed_by', 'weekly_review_rounds_completed_by_index');
            $table->foreign('weekly_plan_id', 'weekly_review_rounds_plan_foreign')
                ->references('id')->on('weekly_plans')->cascadeOnDelete();
            $table->foreign('started_by', 'weekly_review_rounds_started_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('completed_by', 'weekly_review_rounds_completed_by_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('weekly_plan_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_plan_review_round_id');
            $table->foreignId('assignee_id');
            $table->string('status', 32)->default('PENDING');
            $table->foreignId('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['weekly_plan_review_round_id', 'assignee_id'],
                'weekly_review_decisions_round_assignee_unique',
            );
            $table->index('assignee_id', 'weekly_review_decisions_assignee_index');
            $table->index('reviewed_by', 'weekly_review_decisions_reviewed_by_index');
            $table->foreign('weekly_plan_review_round_id', 'weekly_review_decisions_round_foreign')
                ->references('id')->on('weekly_plan_review_rounds')->cascadeOnDelete();
            $table->foreign('assignee_id', 'weekly_review_decisions_assignee_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewed_by', 'weekly_review_decisions_reviewer_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });

        $this->bootstrapLegacyOwnerReviewRounds();
    }

    public function down(): void
    {
        DB::table('weekly_plans')->where('status', 'OWNER_REVIEW')->update(['status' => 'IN_REVIEW']);
        DB::table('weekly_plans')->where('status', 'OWNER_CHANGES_REQUESTED')->update(['status' => 'CHANGES_REQUESTED']);
        DB::table('weekly_plans')->where('status', 'ASSISTANT_REVIEW')->update(['status' => 'SUBMITTED']);
        DB::table('weekly_plans')->where('status', 'ASSISTANT_CHANGES_REQUESTED')->update(['status' => 'CHANGES_REQUESTED']);

        Schema::dropIfExists('weekly_plan_review_decisions');
        Schema::dropIfExists('weekly_plan_review_rounds');
    }

    private function bootstrapLegacyOwnerReviewRounds(): void
    {
        DB::transaction(function (): void {
            $plans = DB::table('weekly_plans')
                ->whereIn('status', [
                    'SUBMITTED',
                    'IN_REVIEW',
                    'CHANGES_REQUESTED',
                    'OWNER_REVIEW',
                    'OWNER_CHANGES_REQUESTED',
                ])
                ->orderBy('id')
                ->get();

            foreach ($plans as $plan) {
                $changesRequested = in_array($plan->status, [
                    'CHANGES_REQUESTED',
                    'OWNER_CHANGES_REQUESTED',
                ], true);
                $normalizedStatus = $changesRequested
                    ? 'OWNER_CHANGES_REQUESTED'
                    : 'OWNER_REVIEW';

                DB::table('weekly_plans')->where('id', $plan->id)->update([
                    'status' => $normalizedStatus,
                    'updated_at' => Carbon::now('UTC'),
                ]);

                $roundId = DB::table('weekly_plan_review_rounds')
                    ->where('weekly_plan_id', $plan->id)
                    ->where('stage', 'OWNER')
                    ->where('round_number', 1)
                    ->value('id');

                if ($roundId === null) {
                    $roundId = DB::table('weekly_plan_review_rounds')->insertGetId([
                        'weekly_plan_id' => $plan->id,
                        'stage' => 'OWNER',
                        'round_number' => 1,
                        'status' => $changesRequested ? 'CHANGES_REQUESTED' : 'OPEN',
                        'started_by' => $plan->submitted_by,
                        'started_at' => $plan->submitted_at ?? $plan->updated_at ?? Carbon::now('UTC'),
                        'completed_by' => null,
                        'completed_at' => null,
                        'created_at' => Carbon::now('UTC'),
                        'updated_at' => Carbon::now('UTC'),
                    ]);
                }

                $assigneeIds = DB::table('weekly_plan_entries')
                    ->where('weekly_plan_id', $plan->id)
                    ->whereNotNull('assignee_id')
                    ->distinct()
                    ->orderBy('assignee_id')
                    ->pluck('assignee_id');

                foreach ($assigneeIds as $assigneeId) {
                    $legacy = DB::table('weekly_plan_employee_reviews')
                        ->where('weekly_plan_id', $plan->id)
                        ->where('assignee_id', $assigneeId)
                        ->first();

                    DB::table('weekly_plan_review_decisions')->updateOrInsert(
                        [
                            'weekly_plan_review_round_id' => $roundId,
                            'assignee_id' => $assigneeId,
                        ],
                        [
                            'status' => $legacy?->status ?? 'PENDING',
                            'reviewed_by' => $legacy?->reviewed_by,
                            'reviewed_at' => $legacy?->reviewed_at,
                            'created_at' => Carbon::now('UTC'),
                            'updated_at' => Carbon::now('UTC'),
                        ],
                    );
                }
            }
        });
    }
};
