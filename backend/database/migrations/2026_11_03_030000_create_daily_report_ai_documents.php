<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_report_ai_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('daily_report_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->date('report_date');
            $table->char('cache_key', 64)->unique('daily_ai_document_cache');
            $table->char('source_hash', 64);
            $table->longText('snapshot');
            $table->longText('analysis_config');
            $table->string('status', 24)->default('pending');
            $table->longText('result')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['organization_id', 'report_date'], 'daily_ai_document_date');
        });
        Schema::create('daily_report_ai_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_report_ai_document_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('part');
            $table->longText('input');
            $table->longText('result')->nullable();
            $table->string('status', 24)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->char('lease_token', 36)->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['daily_report_ai_document_id', 'part'], 'daily_ai_unit_part');
        });
    }

    public function down(): void
    {
        if (DB::table('daily_report_ai_documents')->exists() || DB::table('daily_report_ai_units')->exists()) {
            throw new RuntimeException('Daily AI history exists. Disable feature; do not delete its sources.');
        }
        Schema::dropIfExists('daily_report_ai_units');
        Schema::dropIfExists('daily_report_ai_documents');
    }
};
