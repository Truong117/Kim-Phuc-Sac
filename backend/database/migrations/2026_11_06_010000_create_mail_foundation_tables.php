<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->string('provider', 32)->default('gmail_api');
            $table->string('status', 32)->default('disconnected');
            $table->string('email')->nullable();
            $table->string('provider_subject')->nullable();
            $table->string('configuration_hash', 64)->nullable();
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(0);
            $table->timestamps();
        });
        Schema::create('mail_oauth_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('mail_connections')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('state_hash', 64)->unique();
            $table->string('configuration_hash', 64);
            $table->unsignedInteger('connection_revision');
            $table->text('code_verifier');
            $table->timestamp('expires_at')->index();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('mail_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('connection_id')->constrained('mail_connections')->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('recipient_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('kind', 24)->default('test');
            $table->string('provider', 32);
            $table->unsignedInteger('connection_revision');
            $table->string('status', 32)->default('queued');
            $table->text('payload');
            $table->string('provider_message_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'request_key'], 'mail_messages_request_unique');
            $table->index(['status', 'available_at'], 'mail_messages_dispatch_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
        Schema::dropIfExists('mail_oauth_attempts');
        Schema::dropIfExists('mail_connections');
    }
};
