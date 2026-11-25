<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_holidays', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->date('start_date');
            $table->date('end_date');
            $table->string('note', 240)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'is_active', 'start_date', 'end_date'], 'report_holidays_calendar_index');
        });

        Schema::create('report_holiday_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('report_holiday_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_user_id')->constrained('users')->restrictOnDelete();
            $table->json('before_state')->nullable();
            $table->json('after_state');
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        if ((Schema::hasTable('report_holiday_changes') && DB::table('report_holiday_changes')->exists())
            || (Schema::hasTable('report_holidays') && DB::table('report_holidays')->exists())) {
            throw new RuntimeException('Cannot roll back configured report holidays or their audit history.');
        }

        Schema::dropIfExists('report_holiday_changes');
        Schema::dropIfExists('report_holidays');
    }
};
