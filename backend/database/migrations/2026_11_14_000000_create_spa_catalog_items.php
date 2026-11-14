<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spa_catalog_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('kind', 12);
            $table->string('name', 100);
            $table->string('identity_hash', 64);
            $table->unsignedBigInteger('price');
            $table->string('unit', 30)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('group', 100)->nullable();
            $table->text('description')->nullable();
            $table->boolean('requires_skin_photo')->default(false);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'kind', 'identity_hash'], 'spa_catalog_name_once');
            $table->index(['organization_id', 'kind', 'name'], 'spa_catalog_lookup');
        });
    }

    public function down(): void
    {
        if (DB::table('spa_catalog_items')->exists()) {
            throw new RuntimeException('Spa catalog contains saved data. Roll back application code only.');
        }
        Schema::dropIfExists('spa_catalog_items');
    }
};
