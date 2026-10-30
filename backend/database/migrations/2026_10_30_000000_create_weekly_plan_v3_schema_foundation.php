<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('weekly_plans', function (Blueprint $table): void {
            $table->unsignedTinyInteger('workflow_version')
                ->default(2)
                ->after('status');
            $table->index('workflow_version', 'weekly_plans_workflow_version_index');
        });

        Schema::create('weekly_plan_employee_workflows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('weekly_plan_id');
            $table->unsignedBigInteger('assignee_id');
            $table->string('state', 32);
            $table->unsignedBigInteger('state_version')->default(1);
            $table->unsignedBigInteger('content_version')->default(1);
            $table->string('correction_origin_stage', 32)->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('published_task_count')->default(0);
            $table->timestamps();

            $table->unique(
                ['weekly_plan_id', 'assignee_id'],
                'weekly_employee_workflows_plan_assignee_unique',
            );
            $table->index(
                ['weekly_plan_id', 'state'],
                'weekly_employee_workflows_plan_state_index',
            );
            $table->index('published_by', 'weekly_employee_workflows_publisher_index');
            $table->foreign('weekly_plan_id', 'weekly_employee_workflows_plan_foreign')
                ->references('id')->on('weekly_plans')->cascadeOnDelete();
            $table->foreign('assignee_id', 'weekly_employee_workflows_assignee_foreign')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('published_by', 'weekly_employee_workflows_publisher_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('weekly_plan_v3_review_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('weekly_plan_id');
            $table->string('stage', 32);
            $table->string('request_id', 64);
            $table->char('payload_sha256', 64);
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->string('reviewer_name');
            $table->timestamp('confirmed_at');
            $table->timestamp('created_at');

            $table->unique(
                ['weekly_plan_id', 'stage', 'request_id'],
                'weekly_v3_batches_plan_stage_request_unique',
            );
            $table->index(
                ['weekly_plan_id', 'stage', 'confirmed_at'],
                'weekly_v3_batches_plan_stage_confirmed_index',
            );
            $table->index('reviewer_id', 'weekly_v3_batches_reviewer_index');
            $table->foreign('weekly_plan_id', 'weekly_v3_batches_plan_foreign')
                ->references('id')->on('weekly_plans')->restrictOnDelete();
            $table->foreign('reviewer_id', 'weekly_v3_batches_reviewer_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('weekly_plan_v3_review_decisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('weekly_plan_v3_review_batch_id');
            $table->unsignedBigInteger('weekly_plan_employee_workflow_id');
            $table->unsignedBigInteger('assignee_id');
            $table->string('decision', 32);
            $table->string('from_state', 32);
            $table->string('to_state', 32);
            $table->unsignedBigInteger('expected_state_version');
            $table->unsignedBigInteger('expected_content_version');
            $table->char('content_sha256', 64);
            $table->json('content_snapshot');
            $table->timestamp('created_at');

            $table->unique(
                ['weekly_plan_v3_review_batch_id', 'weekly_plan_employee_workflow_id'],
                'weekly_v3_decisions_batch_workflow_unique',
            );
            $table->index(
                ['weekly_plan_employee_workflow_id', 'created_at'],
                'weekly_v3_decisions_workflow_created_index',
            );
            $table->index('assignee_id', 'weekly_v3_decisions_assignee_index');
            $table->foreign('weekly_plan_v3_review_batch_id', 'weekly_v3_decisions_batch_foreign')
                ->references('id')->on('weekly_plan_v3_review_batches')->restrictOnDelete();
            $table->foreign('weekly_plan_employee_workflow_id', 'weekly_v3_decisions_workflow_foreign')
                ->references('id')->on('weekly_plan_employee_workflows')->restrictOnDelete();
            $table->foreign('assignee_id', 'weekly_v3_decisions_assignee_foreign')
                ->references('id')->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('weekly_plans', 'workflow_version')
            && DB::table('weekly_plans')->where('workflow_version', '<>', 2)->exists()) {
            throw new RuntimeException(
                'Cannot roll back the Weekly Planning V3 foundation while non-V2 plans exist.',
            );
        }

        foreach ([
            'weekly_plan_v3_review_decisions',
            'weekly_plan_v3_review_batches',
            'weekly_plan_employee_workflows',
        ] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException(
                    'Cannot roll back the Weekly Planning V3 foundation while V3 workflow data exists.',
                );
            }
        }

        Schema::dropIfExists('weekly_plan_v3_review_decisions');
        Schema::dropIfExists('weekly_plan_v3_review_batches');
        Schema::dropIfExists('weekly_plan_employee_workflows');

        Schema::table('weekly_plans', function (Blueprint $table): void {
            $table->dropIndex('weekly_plans_workflow_version_index');
            $table->dropColumn('workflow_version');
        });
    }
};
