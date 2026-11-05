<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_entry_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('page_id', 64);
            $table->string('page_name', 255);
            $table->text('access_token')->nullable();
            $table->string('status', 32)->default('connected');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('lease_token', 36)->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->json('sync_counts')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'page_id']);
        });
        Schema::create('social_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('external_id', 128);
            $table->string('url', 1000);
            $table->timestamp('sampled_at')->nullable();
            $table->timestamps();
            $table->unique(['integration_entry_id', 'external_id']);
        });
        Schema::create('social_post_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('daily_report_item_id')->constrained()->cascadeOnDelete();
            $table->string('link_hash', 64);
            $table->unique(['social_post_id', 'daily_report_item_id', 'link_hash'], 'social_sources_unique');
        });
        Schema::create('social_metric_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_post_id')->constrained()->cascadeOnDelete();
            $table->string('definition', 128);
            $table->timestamp('bucket');
            $table->timestamp('sampled_at');
            $table->json('metrics');
            $table->unique(['social_post_id', 'definition', 'bucket'], 'social_snapshot_bucket_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_metric_snapshots');
        Schema::dropIfExists('social_post_sources');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('social_connections');
    }
};
