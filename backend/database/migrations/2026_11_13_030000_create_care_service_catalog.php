<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('name', 200);
            $table->string('identity_hash', 64);
            $table->timestamps();
            $table->unique(['organization_id', 'identity_hash'], 'care_service_name_once');
        });
    }

    public function down(): void
    {
        if (DB::table('care_services')->exists()) {
            throw new RuntimeException('Care service catalog is in use. Roll back application code only.');
        }
        Schema::dropIfExists('care_services');
    }
};
