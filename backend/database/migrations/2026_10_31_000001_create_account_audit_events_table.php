<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 64);
            // Preserve historical identifiers even if an account is later deleted.
            $table->unsignedBigInteger('actor_user_id');
            $table->unsignedBigInteger('subject_user_id');
            $table->string('previous_email');
            $table->string('email');
            $table->timestamp('created_at');
            $table->index(['subject_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('account_audit_events') && DB::table('account_audit_events')->exists()) {
            throw new RuntimeException('Cannot roll back committed account audit events. Use an approved retention-aware recovery plan.');
        }

        Schema::dropIfExists('account_audit_events');
    }
};
