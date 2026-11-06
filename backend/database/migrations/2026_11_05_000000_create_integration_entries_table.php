<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->string('platform', 24)->nullable();
            $table->string('name', 150);
            $table->string('url', 1000);
            $table->string('url_hash', 64);
            $table->foreignId('responsible_user_id')->constrained('users')->restrictOnDelete();
            $table->text('note')->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'kind', 'url_hash'], 'integration_entries_url_unique');
            $table->index(['organization_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_entries');
    }
};
