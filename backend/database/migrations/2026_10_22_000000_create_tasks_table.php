<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('creator_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('assignee_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 20);
            $table->string('status', 20)->default('TODO');
            $table->date('start_date')->nullable();
            $table->date('due_date');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'due_date'], 'tasks_org_status_due_index');
            $table->index(['organization_id', 'assignee_id', 'status'], 'tasks_org_assignee_status_index');
            $table->index(['organization_id', 'department_id'], 'tasks_org_department_index');
            $table->index(['organization_id', 'location_id'], 'tasks_org_location_index');
            $table->index(['organization_id', 'creator_id'], 'tasks_org_creator_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
