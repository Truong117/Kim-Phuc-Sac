<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('report_date');
            $table->timestamp('locked_at');
            $table->timestamps();

            $table->unique(['organization_id', 'user_id', 'report_date'], 'daily_reports_author_date_unique');
            $table->index(['organization_id', 'report_date']);
            $table->index(['organization_id', 'department_id', 'report_date'], 'daily_reports_department_date_index');
            $table->index(['organization_id', 'location_id', 'report_date'], 'daily_reports_location_date_index');
            $table->index(['user_id', 'report_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
