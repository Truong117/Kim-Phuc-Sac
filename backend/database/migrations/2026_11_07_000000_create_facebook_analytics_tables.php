<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_analytics_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_connection_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 24)->default('idle');
            $table->text('cursor')->nullable();
            $table->date('from_date')->nullable();
            $table->date('to_date')->nullable();
            $table->boolean('catalogue_complete')->default(false);
            $table->timestamp('catalogued_at')->nullable();
            $table->timestamp('sampled_at')->nullable();
            $table->string('lease_token', 36)->nullable();
            $table->timestamp('leased_until')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->json('counts')->nullable();
            $table->timestamps();
        });
        Schema::create('facebook_analytics_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('social_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('external_id', 128);
            $table->string('url', 1000);
            $table->text('message');
            $table->text('picture')->nullable();
            $table->string('type', 24);
            $table->string('topic', 32)->nullable();
            $table->timestamp('published_at');
            $table->timestamp('sampled_at')->nullable();
            $table->timestamps();
            $table->unique(['social_connection_id', 'external_id'], 'fb_analytics_post_unique');
            $table->index(['social_connection_id', 'published_at'], 'fb_analytics_post_date');
        });
        Schema::create('facebook_analytics_samples', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained('facebook_analytics_posts')->cascadeOnDelete();
            $table->string('definition', 128);
            $table->timestamp('bucket');
            $table->timestamp('sampled_at');
            $table->json('metrics');
            $table->unique(['post_id', 'definition', 'bucket'], 'fb_analytics_sample_unique');
            $table->index(['post_id', 'sampled_at'], 'fb_analytics_sample_date');
        });
        Schema::create('facebook_analytics_analyses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('social_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('connection_revision');
            $table->unsignedInteger('entry_version');
            $table->date('from_date');
            $table->date('to_date');
            $table->string('source_hash', 64);
            $table->string('status', 24)->default('queued');
            $table->longText('snapshot');
            $table->longText('result')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['social_connection_id', 'from_date', 'to_date'], 'fb_analytics_analysis_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_analytics_analyses');
        Schema::dropIfExists('facebook_analytics_samples');
        Schema::dropIfExists('facebook_analytics_posts');
        Schema::dropIfExists('facebook_analytics_states');
    }
};
