<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_analytics_review_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_entry_id')->constrained('integration_entries')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->date('from_date');
            $table->date('to_date');
            $table->unsignedInteger('version')->default(1);
            $table->unsignedSmallInteger('expected_posts')->nullable();
            $table->text('actions');
            $table->string('source_hash', 64);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['integration_entry_id', 'from_date', 'to_date'], 'fb_review_plan_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_analytics_review_plans');
    }
};
