<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_customer_profiles', function (Blueprint $table): void {
            $table->foreignId('customer_id')->primary()->constrained('care_customers')->restrictOnDelete();
            $table->foreignId('owner_membership_id')->nullable()->constrained('organization_memberships')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });

        Schema::create('crm_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained('care_customers')->restrictOnDelete();
            $table->string('title', 200);
            $table->string('stage', 24)->default('NEW');
            $table->string('resolution', 200)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->index(['customer_id', 'id']);
        });

        Schema::create('crm_activities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained('care_customers')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('opportunity_id')->nullable()->constrained('crm_opportunities')->restrictOnDelete();
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->string('kind', 24);
            $table->date('business_date');
            $table->date('due_on')->nullable();
            $table->string('title', 200);
            $table->text('result')->nullable();
            $table->json('services')->nullable();
            $table->timestamp('created_at');
            $table->unique(['organization_id', 'actor_id', 'request_key'], 'crm_activity_request_once');
            $table->index(['organization_id', 'actor_id', 'business_date'], 'crm_activity_author_date');
            $table->index(['organization_id', 'actor_id', 'due_on'], 'crm_activity_author_due');
            $table->index(['customer_id', 'id']);
        });

        // Created last. The application remains gated until this migration is ledgered.
        Schema::create('crm_report_activities', function (Blueprint $table): void {
            $table->foreignId('report_id')->constrained('daily_reports')->cascadeOnDelete();
            $table->foreignId('activity_id')->constrained('crm_activities')->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->restrictOnDelete();
            $table->primary(['report_id', 'activity_id']);
        });
    }

    public function down(): void
    {
        foreach (['crm_customer_profiles', 'crm_opportunities', 'crm_activities', 'crm_report_activities'] as $table) {
            if (DB::table($table)->exists()) {
                throw new RuntimeException('Cannot roll back a populated CRM migration.');
            }
        }

        Schema::dropIfExists('crm_report_activities');
        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('crm_opportunities');
        Schema::dropIfExists('crm_customer_profiles');
    }
};
