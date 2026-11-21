<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_report_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_report_id')->constrained()->restrictOnDelete();
            // Preserve actor/subject identifiers and names independently of account changes.
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('subject_user_id');
            $table->string('actor_name');
            $table->string('actor_role', 32);
            $table->boolean('visible_in_report');
            $table->text('reason')->nullable();
            $table->longText('encrypted_changes');
            $table->timestamp('created_at');
            $table->index(['daily_report_id', 'id']);
            $table->index(['daily_report_id', 'visible_in_report', 'id'], 'daily_report_amendment_visibility_index');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('daily_report_amendments') && DB::table('daily_report_amendments')->exists()) {
            throw new RuntimeException('Cannot roll back committed daily report amendments. Use an approved retention-aware recovery plan.');
        }

        Schema::dropIfExists('daily_report_amendments');
    }
};
