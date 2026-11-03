<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_reports', function (Blueprint $table): void {
            $table->boolean('is_makeup')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('daily_reports', fn (Blueprint $table) => $table->dropColumn('is_makeup'));
    }
};
