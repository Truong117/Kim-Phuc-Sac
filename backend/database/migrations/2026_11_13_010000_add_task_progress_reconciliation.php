<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_outputs', fn (Blueprint $table) => $table->string('reference', 200)->nullable());
        Schema::table('task_progress_events', fn (Blueprint $table) => $table->string('origin', 32)->default('daily_report'));
        Schema::create('task_progress_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('report_id')->constrained('daily_reports')->restrictOnDelete();
            $table->foreignId('report_item_id')->constrained('daily_report_items')->restrictOnDelete();
            $table->date('report_date');
            $table->string('unit', 32);
            $table->string('source_hash', 64);
            $table->string('selection_hash', 64);
            $table->json('outputs');
            $table->text('confirmation_note');
            $table->timestamp('created_at');
            $table->unique(['task_id', 'report_item_id'], 'task_progress_import_once');
        });
    }

    public function down(): void
    {
        if (DB::table('task_progress_imports')->exists() || DB::table('task_outputs')->whereNotNull('reference')->exists()) {
            throw new RuntimeException('Cannot drop reconciled task progress or customer identities. Roll back application code only.');
        }
        Schema::dropIfExists('task_progress_imports');
        Schema::table('task_progress_events', fn (Blueprint $table) => $table->dropColumn('origin'));
        Schema::table('task_outputs', fn (Blueprint $table) => $table->dropColumn('reference'));
    }
};
