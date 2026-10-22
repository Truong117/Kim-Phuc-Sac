<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('week_start');
            $table->date('week_end');
            $table->string('status', 32)->default('DRAFT');
            $table->string('source_disk', 64)->nullable();
            $table->string('source_path', 1024)->nullable();
            $table->string('original_filename', 255);
            $table->string('source_extension', 12);
            $table->string('source_mime', 128);
            $table->unsignedBigInteger('source_size');
            $table->char('source_sha256', 64);
            $table->string('parse_status', 32);
            $table->json('parse_metadata')->nullable();
            $table->timestamp('uploaded_at');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'week_start'], 'weekly_plans_org_week_unique');
            $table->index(['organization_id', 'status', 'week_start'], 'weekly_plans_org_status_week_index');
        });

        Schema::create('weekly_plan_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('employee_identifier')->nullable();
            $table->string('employee_name')->nullable();
            $table->string('department_name')->nullable();
            $table->string('title');
            $table->text('content')->nullable();
            $table->text('expected_result')->nullable();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->string('priority', 20)->nullable();
            $table->text('note')->nullable();
            $table->json('source_payload')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('validation_status', 16)->default('INVALID');
            $table->json('validation_errors')->nullable();
            $table->timestamps();

            $table->index(['weekly_plan_id', 'assignee_id', 'sort_order'], 'weekly_entries_plan_assignee_sort_index');
            $table->index(['weekly_plan_id', 'validation_status'], 'weekly_entries_plan_validation_index');
        });

        Schema::create('weekly_plan_employee_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 32)->default('PENDING');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['weekly_plan_id', 'assignee_id'], 'weekly_reviews_plan_assignee_unique');
        });

        Schema::create('weekly_plan_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('weekly_plan_entry_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('content');
            $table->timestamp('created_at');

            $table->index(['weekly_plan_id', 'assignee_id', 'created_at'], 'weekly_comments_plan_assignee_index');
        });

        Schema::create('weekly_plan_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('weekly_plan_entry_id')->constrained()->cascadeOnDelete();
            $table->string('type', 64);
            $table->text('original_value')->nullable();
            $table->text('suggested_value')->nullable();
            $table->text('reason');
            $table->string('status', 16)->default('PENDING');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['weekly_plan_id', 'status'], 'weekly_suggestions_plan_status_index');
        });

        Schema::create('weekly_plan_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event_type', 64);
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['weekly_plan_id', 'created_at'], 'weekly_events_plan_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_plan_events');
        Schema::dropIfExists('weekly_plan_suggestions');
        Schema::dropIfExists('weekly_plan_comments');
        Schema::dropIfExists('weekly_plan_employee_reviews');
        Schema::dropIfExists('weekly_plan_entries');
        Schema::dropIfExists('weekly_plans');
    }
};
