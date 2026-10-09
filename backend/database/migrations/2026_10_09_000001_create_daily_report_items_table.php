<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_report_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('daily_report_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->text('result');
            $table->string('status', 32);
            $table->text('note')->nullable();
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['daily_report_id', 'sort_order'], 'daily_report_items_sort_unique');
            $table->index(['daily_report_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_report_items');
    }
};
