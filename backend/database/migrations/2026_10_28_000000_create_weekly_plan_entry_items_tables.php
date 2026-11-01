<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_plan_entry_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weekly_plan_entry_id')
                ->constrained('weekly_plan_entries')
                ->cascadeOnDelete();
            $table->string('title', 255);
            $table->text('content')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('source_payload')->nullable();
            $table->timestamps();

            $table->index(
                ['weekly_plan_entry_id', 'sort_order', 'id'],
                'weekly_entry_items_entry_sort_index',
            );
        });

        Schema::create('weekly_plan_entry_item_source_passage', function (Blueprint $table): void {
            $table->unsignedBigInteger('weekly_plan_entry_item_id');
            $table->unsignedBigInteger('weekly_plan_source_passage_id');

            $table->foreign(
                'weekly_plan_entry_item_id',
                'weekly_item_passage_item_foreign',
            )
                ->references('id')
                ->on('weekly_plan_entry_items')
                ->cascadeOnDelete();
            $table->foreign(
                'weekly_plan_source_passage_id',
                'weekly_item_passage_passage_foreign',
            )
                ->references('id')
                ->on('weekly_plan_source_passages')
                ->cascadeOnDelete();

            $table->primary(
                ['weekly_plan_entry_item_id', 'weekly_plan_source_passage_id'],
                'weekly_item_passage_primary',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('weekly_plan_entry_item_source_passage');
        Schema::dropIfExists('weekly_plan_entry_items');
    }
};
