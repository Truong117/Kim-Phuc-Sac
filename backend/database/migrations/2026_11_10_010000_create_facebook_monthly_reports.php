<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_analytics_monthly_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('integration_entry_id')->constrained('integration_entries')->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained('organizations')->restrictOnDelete();
            $table->date('month');
            $table->string('source_hash', 64);
            $table->string('request_hash', 64);
            $table->longText('snapshot');
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at');
            $table->unique(['integration_entry_id', 'month'], 'fb_monthly_report_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facebook_analytics_monthly_reports');
    }
};
