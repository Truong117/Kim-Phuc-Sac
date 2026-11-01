<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // M23 remains immutable. All revision metadata is additive and explicit.
        Schema::create('work_report_analysis_revisions', function (Blueprint $table): void {
            $table->foreignId('analysis_id')->primary()->constrained('work_report_analyses')->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_analysis_id')->constrained('work_report_analyses')->restrictOnDelete();
            $table->foreignId('root_analysis_id')->constrained('work_report_analyses')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->char('fingerprint', 64);
            $table->longText('review_feedback')->nullable();
            $table->timestamps();
            $table->unique(['root_analysis_id', 'revision_number'], 'work_revision_number_unique');
            $table->index(['organization_id', 'fingerprint'], 'work_revision_equivalence_index');
        });
        Schema::create('work_report_regeneration_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('source_analysis_id')->constrained('work_report_analyses')->restrictOnDelete();
            $table->foreignId('result_analysis_id')->constrained('work_report_analyses')->restrictOnDelete();
            $table->char('idempotency_key', 36);
            $table->char('request_hash', 64);
            $table->boolean('reused_existing')->default(false);
            $table->timestamps();
            $table->unique(['organization_id', 'requested_by_user_id', 'idempotency_key'], 'work_regeneration_operation_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('work_report_analysis_revisions')->exists() || DB::table('work_report_regeneration_requests')->exists()) {
            throw new RuntimeException('Cannot drop persisted analysis revision history. Disable the feature instead.');
        }
        Schema::dropIfExists('work_report_regeneration_requests');
        Schema::dropIfExists('work_report_analysis_revisions');
    }
};
