<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive V2 storage. V1 requester-private rows and their unique key stay intact.
        Schema::create('work_report_analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->char('cache_key', 64)->unique('work_analysis_cache_unique');
            $table->char('scope_hash', 64);
            $table->char('source_hash', 64);
            $table->date('start_date');
            $table->date('end_date');
            $table->unsignedTinyInteger('days');
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->longText('snapshot');
            $table->longText('analysis_config');
            $table->longText('result')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('reserved_cost_microusd')->default(0);
            $table->timestamps();
            $table->index(['organization_id', 'scope_hash', 'end_date'], 'work_analysis_scope_history');
        });
        Schema::create('work_report_analysis_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('work_report_analysis_id')->constrained()->restrictOnDelete();
            $table->string('unit_key', 64);
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->char('lease_token', 36)->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->longText('input');
            $table->longText('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->foreignId('ai_usage_record_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('cost_microusd')->nullable();
            $table->timestamps();
            $table->unique(['work_report_analysis_id', 'unit_key'], 'work_analysis_unit_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('work_report_analyses')->exists()
            || DB::table('work_report_analysis_units')->exists()) {
            throw new RuntimeException('Cannot roll back persisted work-report analysis history. Disable the feature instead.');
        }
        Schema::dropIfExists('work_report_analysis_units');
        Schema::dropIfExists('work_report_analyses');
    }
};
