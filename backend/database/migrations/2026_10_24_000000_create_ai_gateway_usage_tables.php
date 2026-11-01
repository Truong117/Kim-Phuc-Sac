<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_budget_periods', function (Blueprint $table): void {
            $table->id();
            $table->string('environment', 32);
            $table->string('provider', 24);
            $table->char('billing_period', 7);
            $table->unsignedBigInteger('reserved_cost_microusd')->default(0);
            $table->unsignedBigInteger('spent_cost_microusd')->default(0);
            $table->timestamps();

            $table->unique(
                ['environment', 'provider', 'billing_period'],
                'ai_budget_period_environment_provider_unique',
            );
        });

        Schema::create('ai_usage_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ai_budget_period_id')
                ->constrained('ai_budget_periods')
                ->restrictOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('feature', 64);
            $table->char('request_fingerprint', 64);
            $table->string('status', 24);
            $table->string('provider', 24);
            $table->string('model', 100);
            $table->unsignedInteger('reservation_version')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedBigInteger('reserved_cost_microusd')->default(0);
            $table->unsignedBigInteger('estimated_cost_microusd')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code', 64)->nullable();
            // The structured result may still contain internal work content.
            // Store the cache encrypted with APP_KEY instead of readable JSON.
            $table->longText('response_payload')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['organization_id', 'feature', 'request_fingerprint'],
                'ai_usage_org_feature_fingerprint_unique',
            );
            $table->index(
                ['organization_id', 'feature', 'created_at'],
                'ai_usage_org_feature_created_index',
            );
            $table->index(['status', 'started_at'], 'ai_usage_status_started_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_records');
        Schema::dropIfExists('ai_budget_periods');
    }
};
