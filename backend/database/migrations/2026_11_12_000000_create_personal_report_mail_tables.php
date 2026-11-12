<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_mail_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->boolean('weekly_enabled')->default(false);
            $table->timestamp('weekly_enabled_at')->nullable();
            $table->string('verified_email_hash', 64)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->uuid('verification_key')->nullable()->unique();
            $table->string('verification_email_hash', 64)->nullable();
            $table->string('verification_token_hash', 64)->nullable()->unique();
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamp('verification_requested_at')->nullable();
            $table->foreignUuid('verification_mail_message_id')->nullable()->constrained('mail_messages')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id'], 'report_mail_preference_owner');
        });
        Schema::create('personal_weekly_mail_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('cycle_id')->constrained('report_automation_cycles')->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('end_date');
            $table->foreignId('analysis_id')->nullable()->constrained('work_report_analyses')->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->string('email_hash', 64)->nullable();
            $table->foreignUuid('mail_message_id')->nullable()->unique()->constrained('mail_messages')->restrictOnDelete();
            $table->string('status', 32)->default('waiting_analysis');
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'user_id', 'end_date'], 'personal_weekly_mail_once');
        });
        Schema::table('report_automation_cycles', function (Blueprint $table): void {
            $table->timestamp('personal_mail_next_check_at')->nullable()->index();
            $table->timestamp('personal_mail_finished_at')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('report_mail_preferences')->exists() || DB::table('personal_weekly_mail_deliveries')->exists()) {
            throw new RuntimeException('Cannot drop personal report mail consent or delivery history. Disable the feature instead.');
        }
        Schema::table('report_automation_cycles', function (Blueprint $table): void {
            $table->dropIndex(['personal_mail_next_check_at']);
            $table->dropColumn(['personal_mail_next_check_at', 'personal_mail_finished_at']);
        });
        Schema::dropIfExists('personal_weekly_mail_deliveries');
        Schema::dropIfExists('report_mail_preferences');
    }
};
