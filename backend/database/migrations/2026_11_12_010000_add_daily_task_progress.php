<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedInteger('target_quantity')->nullable();
            $table->string('quantity_unit', 32)->nullable();
            $table->unsignedInteger('progress_revision')->default(0);
        });
        Schema::table('daily_report_items', function (Blueprint $table): void {
            $table->foreignId('task_id')->nullable()->constrained('tasks')->restrictOnDelete();
            $table->json('task_snapshot')->nullable();
            $table->json('task_progress')->nullable();
            $table->unique(['daily_report_id', 'task_id'], 'daily_report_task_once');
        });
        Schema::create('task_outputs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('identity_hash', 64);
            $table->string('unit', 32);
            $table->boolean('completed')->default(false);
            $table->date('first_completed_on')->nullable();
            $table->foreignId('first_report_item_id')->nullable()->constrained('daily_report_items')->nullOnDelete();
            $table->timestamps();
            $table->unique(['task_id', 'unit', 'identity_hash'], 'task_output_identity');
        });
        Schema::create('task_progress_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('report_id')->constrained('daily_reports')->restrictOnDelete();
            $table->foreignId('report_item_id')->nullable()->constrained('daily_report_items')->nullOnDelete();
            $table->date('report_date');
            $table->boolean('applied');
            $table->string('status_before', 32);
            $table->string('status_after', 32);
            $table->json('outputs');
            $table->timestamp('created_at');
            $table->index(['task_id', 'id']);
        });
    }

    public function down(): void
    {
        if (DB::table('task_outputs')->exists() || DB::table('task_progress_events')->exists()
            || DB::table('daily_report_items')->whereNotNull('task_id')->exists()
            || DB::table('tasks')->whereNotNull('target_quantity')->orWhereNotNull('quantity_unit')->exists()) {
            throw new RuntimeException('Cannot drop task progress history. Keep the migration and roll back application code only.');
        }
        Schema::dropIfExists('task_progress_events');
        Schema::dropIfExists('task_outputs');
        Schema::table('daily_report_items', function (Blueprint $table): void {
            $table->dropUnique('daily_report_task_once');
            $table->dropConstrainedForeignId('task_id');
            $table->dropColumn(['task_snapshot', 'task_progress']);
        });
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn(['target_quantity', 'quantity_unit', 'progress_revision']));
    }
};
