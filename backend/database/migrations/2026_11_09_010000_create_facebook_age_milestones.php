<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_analytics_posts', function (Blueprint $table): void {
            $table->string('objective', 32)->nullable();
            $table->string('distribution', 16)->nullable();
            $table->foreignId('classified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('classified_at')->nullable();
        });
        Schema::create('facebook_analytics_milestones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained('facebook_analytics_posts')->cascadeOnDelete();
            $table->unsignedTinyInteger('milestone_day');
            $table->unsignedSmallInteger('policy_version');
            $table->string('definition', 128);
            $table->timestamp('published_at');
            $table->timestamp('due_at');
            $table->timestamp('sampled_at');
            $table->string('quality', 16);
            $table->json('metrics');
            $table->timestamp('created_at');
            $table->unique(['post_id', 'milestone_day', 'policy_version', 'definition', 'published_at'], 'fb_age_milestone_unique');
            $table->index(['post_id', 'sampled_at'], 'fb_age_milestone_observed');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_analytics_milestones');
        Schema::table('facebook_analytics_posts', function (Blueprint $table): void {
            $table->dropForeign(['classified_by']);
            $table->dropColumn(['objective', 'distribution', 'classified_by', 'classified_at']);
        });
    }
};
