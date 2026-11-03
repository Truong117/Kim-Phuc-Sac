<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table): void {
            $table->text('next_day_plan')->nullable();
        });
        Schema::table('daily_report_items', function (Blueprint $table): void {
            $table->text('issue')->nullable();
            $table->json('evidence_links')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_report_items', function (Blueprint $table): void {
            $table->dropColumn(['issue', 'evidence_links']);
        });
        Schema::table('daily_reports', function (Blueprint $table): void {
            $table->dropColumn('next_day_plan');
        });
    }
};
