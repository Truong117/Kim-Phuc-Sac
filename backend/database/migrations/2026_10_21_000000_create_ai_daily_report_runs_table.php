<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_daily_report_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->date('report_date');
            $table->string('status', 24)->default('pending');
            $table->json('source_report_ids')->nullable();
            $table->json('work_counts')->nullable();
            $table->json('result')->nullable();
            $table->string('provider', 24)->nullable();
            $table->string('model', 100)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->string('error_code', 40)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'requested_by_user_id', 'report_date'], 'ai_daily_runs_requester_date_unique');
            $table->index(['organization_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_daily_report_runs');
    }
};
