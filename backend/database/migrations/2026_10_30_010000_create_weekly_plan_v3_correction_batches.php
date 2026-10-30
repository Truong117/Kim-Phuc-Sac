<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_plan_v3_correction_batches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('weekly_plan_id');
            $table->string('request_id', 64);
            $table->char('payload_sha256', 64);
            $table->unsignedBigInteger('submitter_id')->nullable();
            $table->string('submitter_name');
            $table->json('result_snapshot');
            $table->timestamp('confirmed_at');
            $table->timestamp('created_at');

            $table->unique(
                ['weekly_plan_id', 'request_id'],
                'weekly_v3_correction_batches_plan_request_unique',
            );
            $table->index(
                ['weekly_plan_id', 'confirmed_at'],
                'weekly_v3_correction_batches_plan_confirmed_index',
            );
            $table->index('submitter_id', 'weekly_v3_correction_batches_submitter_index');
            $table->foreign('weekly_plan_id', 'weekly_v3_correction_batches_plan_foreign')
                ->references('id')->on('weekly_plans')->restrictOnDelete();
            $table->foreign('submitter_id', 'weekly_v3_correction_batches_submitter_foreign')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('weekly_plan_v3_correction_batches')
            && DB::table('weekly_plan_v3_correction_batches')->exists()) {
            throw new RuntimeException(
                'Cannot roll back V3 correction batches after confirmed corrections exist.',
            );
        }

        Schema::dropIfExists('weekly_plan_v3_correction_batches');
    }
};
