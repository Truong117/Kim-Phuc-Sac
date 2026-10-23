<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_plan_source_passages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_plan_id')->constrained()->cascadeOnDelete();
            $table->char('source_sha256', 64);
            $table->string('source_key', 128);
            $table->unsignedInteger('sequence');
            $table->string('kind', 32);
            $table->text('text');
            $table->json('metadata')->nullable();
            $table->char('content_sha256', 64);
            $table->timestamps();

            $table->unique(
                ['weekly_plan_id', 'source_sha256', 'source_key'],
                'weekly_passages_plan_source_key_unique',
            );
            $table->unique(
                ['weekly_plan_id', 'source_sha256', 'sequence'],
                'weekly_passages_plan_source_sequence_unique',
            );
        });

        Schema::create('weekly_plan_entry_source_passage', function (Blueprint $table): void {
            $table->unsignedBigInteger('weekly_plan_entry_id');
            $table->unsignedBigInteger('weekly_plan_source_passage_id');

            $table->foreign(
                'weekly_plan_entry_id',
                'weekly_entry_passage_entry_foreign',
            )
                ->references('id')
                ->on('weekly_plan_entries')
                ->cascadeOnDelete();
            $table->foreign(
                'weekly_plan_source_passage_id',
                'weekly_entry_passage_passage_foreign',
            )
                ->references('id')
                ->on('weekly_plan_source_passages')
                ->cascadeOnDelete();

            $table->primary(
                ['weekly_plan_entry_id', 'weekly_plan_source_passage_id'],
                'weekly_entry_passage_primary',
            );
        });

        Schema::create('weekly_plan_entry_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key');
            $table->char('payload_sha256', 64);
            $table->json('entry_ids');
            $table->timestamp('created_at');

            $table->unique(
                ['weekly_plan_id', 'idempotency_key'],
                'weekly_applications_plan_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_plan_entry_applications');
        Schema::dropIfExists('weekly_plan_entry_source_passage');
        Schema::dropIfExists('weekly_plan_source_passages');
    }
};
