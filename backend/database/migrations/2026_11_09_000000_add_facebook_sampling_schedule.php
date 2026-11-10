<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facebook_analytics_states', function (Blueprint $table): void {
            $table->json('sampling_schedule')->nullable();
            $table->timestamp('sampling_next_check_at')->nullable()->index('fb_sampling_next_check');
        });
        Schema::table('facebook_analytics_samples', function (Blueprint $table): void {
            $table->timestamp('scheduled_for')->nullable();
            $table->boolean('sampling_late')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('facebook_analytics_samples', fn (Blueprint $table) => $table->dropColumn(['scheduled_for', 'sampling_late']));
        Schema::table('facebook_analytics_states', function (Blueprint $table): void {
            $table->dropIndex('fb_sampling_next_check');
            $table->dropColumn(['sampling_schedule', 'sampling_next_check_at']);
        });
    }
};
