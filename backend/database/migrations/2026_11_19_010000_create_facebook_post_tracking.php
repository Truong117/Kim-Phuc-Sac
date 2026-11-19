<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_post_tracking', function (Blueprint $table): void {
            $table->foreignId('social_connection_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 128);
            $table->primary(['social_connection_id', 'external_id']);
            $table->unsignedSmallInteger('failure_count')->default(0);
            $table->uuid('last_failure_run_id')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->unsignedInteger('tracking_version')->default(0);
            $table->timestamp('stopped_at')->nullable();
            $table->foreignId('stopped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('stop_reason', 32)->nullable();
            $table->timestamp('resumed_at')->nullable();
            $table->foreignId('resumed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_post_tracking');
    }
};
