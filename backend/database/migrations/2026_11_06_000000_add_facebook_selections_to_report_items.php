<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_report_items', function (Blueprint $table): void {
            $table->json('facebook_selections')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('daily_report_items', function (Blueprint $table): void {
            $table->dropColumn('facebook_selections');
        });
    }
};
