<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_automation_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->string('kind', 12);
            $table->date('end_date');
            $table->timestamp('due_at');
            $table->timestamp('source_closes_at');
            $table->timestamp('mail_due_at');
            $table->foreignId('analysis_id')->nullable()->constrained('work_report_analyses')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('error_code', 64)->nullable();
            $table->timestamp('next_check_at')->index();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('mail_analysis_id')->nullable()->constrained('work_report_analyses')->restrictOnDelete();
            $table->longText('mail_content')->nullable();
            $table->string('mail_status', 24)->default('waiting');
            $table->timestamp('mail_frozen_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'kind', 'end_date'], 'report_auto_cycle_unique');
        });
        Schema::create('report_automation_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cycle_id')->constrained('report_automation_cycles')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->foreignUuid('mail_message_id')->nullable()->unique()->constrained('mail_messages')->restrictOnDelete();
            $table->string('status', 24)->default('pending');
            $table->string('error_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['cycle_id', 'recipient_user_id'], 'report_auto_recipient_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('report_automation_cycles')->exists()) {
            throw new RuntimeException('Cannot drop report automation history. Disable the feature instead.');
        }
        Schema::dropIfExists('report_automation_deliveries');
        Schema::dropIfExists('report_automation_cycles');
    }
};
