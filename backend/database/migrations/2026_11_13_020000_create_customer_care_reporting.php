<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('care_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('name', 200);
            $table->timestamps();
            $table->unique(['organization_id', 'created_by', 'request_key'], 'care_customer_request_once');
        });
        Schema::create('care_customer_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('care_customers')->restrictOnDelete();
            $table->string('channel', 100);
            $table->string('value', 2048)->nullable();
            $table->string('identity_hash', 64)->nullable();
            $table->unique(['organization_id', 'identity_hash'], 'care_contact_identity_once');
        });
        Schema::table('task_outputs', function (Blueprint $table): void {
            $table->foreignId('care_customer_id')->nullable()->constrained('care_customers')->restrictOnDelete();
            $table->unique(['task_id', 'unit', 'care_customer_id'], 'task_customer_once');
        });
        Schema::create('care_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('name', 200);
            $table->string('mime', 80);
            $table->unsignedInteger('size');
            // Private, bounded evidence survives Render's ephemeral filesystem.
            $table->mediumText('contents');
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('created_at');
            $table->unique(['created_by', 'request_key'], 'care_attachment_request_once');
        });
        Schema::create('care_report_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('report_item_id')->constrained('daily_report_items')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('care_customers')->restrictOnDelete();
            $table->json('activities');
            $table->json('evidence_links');
            $table->json('attachment_ids');
            $table->timestamps();
            $table->unique(['report_item_id', 'customer_id'], 'care_report_customer_once');
        });
    }

    public function down(): void
    {
        foreach (['care_customers', 'care_attachments', 'care_report_entries'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Customer care data exists. Roll back application code only.');
            }
        }
        Schema::dropIfExists('care_report_entries');
        Schema::dropIfExists('care_attachments');
        Schema::table('task_outputs', function (Blueprint $table): void {
            $table->dropUnique('task_customer_once');
            $table->dropConstrainedForeignId('care_customer_id');
        });
        Schema::dropIfExists('care_customer_contacts');
        Schema::dropIfExists('care_customers');
    }
};
