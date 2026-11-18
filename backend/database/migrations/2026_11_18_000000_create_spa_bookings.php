<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spa_customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('phone', 15);
            $table->text('note')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'phone'], 'spa_customer_phone_once');
            $table->unique(['organization_id', 'id'], 'spa_customer_org_id');
            $table->index(['organization_id', 'name', 'id'], 'spa_customer_lookup');
        });

        Schema::create('spa_appointments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('customer_id');
            $table->foreign(['organization_id', 'customer_id'], 'spa_appointment_customer_fk')
                ->references(['organization_id', 'id'])->on('spa_customers')->restrictOnDelete();
            $table->foreignUuid('service_id')->constrained('spa_catalog_items')->restrictOnDelete();
            $table->string('service_name', 100);
            $table->unsignedBigInteger('service_price');
            $table->unsignedSmallInteger('duration_minutes');
            $table->boolean('requires_skin_photo');
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->string('request_hash', 64);
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'date', 'start_time', 'id'], 'spa_appointment_day');
            $table->index(['organization_id', 'customer_id', 'date', 'status', 'start_time'], 'spa_appointment_customer_time');
        });

        Schema::create('spa_appointment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('appointment_id')->constrained('spa_appointments')->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('action', 20);
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->string('actor_name');
            $table->date('previous_date')->nullable();
            $table->time('previous_start_time')->nullable();
            $table->date('date');
            $table->time('start_time');
            $table->text('reason')->nullable();
            $table->timestamp('created_at');
            $table->unique(['appointment_id', 'version'], 'spa_appointment_event_version');
        });
    }

    public function down(): void
    {
        $tables = ['spa_appointment_events', 'spa_appointments', 'spa_customers'];
        foreach ($tables as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Spa bookings contain saved data. Roll back application code only.');
            }
        }
        foreach ($tables as $table) {
            Schema::dropIfExists($table);
        }
    }
};
