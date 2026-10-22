<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedBigInteger('weekly_plan_entry_id')
                ->nullable()
                ->after('location_id');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->unique('weekly_plan_entry_id', 'tasks_weekly_plan_entry_unique');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreign('weekly_plan_entry_id', 'tasks_weekly_plan_entry_id_foreign')
                ->references('id')
                ->on('weekly_plan_entries')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            // Resolves to tasks_weekly_plan_entry_id_foreign and remains portable to SQLite tests.
            $table->dropForeign(['weekly_plan_entry_id']);
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropUnique('tasks_weekly_plan_entry_unique');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('weekly_plan_entry_id');
        });
    }
};
