<?php

namespace Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AiGatewayMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_schema_contains_budget_usage_constraints_and_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('ai_budget_periods', [
            'environment',
            'provider',
            'billing_period',
            'reserved_cost_microusd',
            'spent_cost_microusd',
        ]));
        $this->assertTrue(Schema::hasColumns('ai_usage_records', [
            'ai_budget_period_id',
            'organization_id',
            'requested_by_user_id',
            'feature',
            'request_fingerprint',
            'status',
            'model',
            'reservation_version',
            'attempts',
            'input_tokens',
            'output_tokens',
            'estimated_cost_microusd',
            'response_payload',
        ]));

        $this->assertUniqueIndex(
            'ai_budget_periods',
            'ai_budget_period_environment_provider_unique',
        );
        $this->assertUniqueIndex(
            'ai_usage_records',
            'ai_usage_org_feature_fingerprint_unique',
        );
        $this->assertForeignKey(
            'ai_usage_records',
            'ai_budget_period_id',
            'ai_budget_periods',
            'id',
            'RESTRICT',
        );
        $this->assertForeignKey(
            'ai_usage_records',
            'organization_id',
            'organizations',
            'id',
            'RESTRICT',
        );
        $this->assertForeignKey(
            'ai_usage_records',
            'requested_by_user_id',
            'users',
            'id',
            'RESTRICT',
        );

        foreach ([
            'ai_budget_period_environment_provider_unique',
            'ai_usage_org_feature_fingerprint_unique',
            'ai_usage_org_feature_created_index',
            'ai_usage_status_started_index',
        ] as $identifier) {
            $this->assertLessThanOrEqual(64, strlen($identifier));
        }
    }

    public function test_gateway_migration_rolls_back_and_recreates_in_dependency_order(): void
    {
        $migration = require database_path(
            'migrations/2026_10_24_000000_create_ai_gateway_usage_tables.php',
        );
        $this->assertInstanceOf(Migration::class, $migration);

        $migration->down();
        $this->assertFalse(Schema::hasTable('ai_usage_records'));
        $this->assertFalse(Schema::hasTable('ai_budget_periods'));
        $this->assertTrue(Schema::hasTable('organizations'));
        $this->assertTrue(Schema::hasTable('users'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('ai_budget_periods'));
        $this->assertTrue(Schema::hasTable('ai_usage_records'));
    }

    public function test_down_cleans_a_first_table_only_partial_state_in_dependency_order(): void
    {
        $migration = require database_path(
            'migrations/2026_10_24_000000_create_ai_gateway_usage_tables.php',
        );

        Schema::drop('ai_usage_records');
        $this->assertTrue(Schema::hasTable('ai_budget_periods'));
        $this->assertFalse(Schema::hasTable('ai_usage_records'));

        $migration->down();
        $this->assertFalse(Schema::hasTable('ai_usage_records'));
        $this->assertFalse(Schema::hasTable('ai_budget_periods'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('ai_budget_periods'));
        $this->assertTrue(Schema::hasTable('ai_usage_records'));
    }

    private function assertUniqueIndex(string $table, string $indexName): void
    {
        $indexes = collect(DB::select("PRAGMA index_list('{$table}')"));
        $index = $indexes->first(fn (object $candidate): bool => $candidate->name === $indexName);

        $this->assertNotNull($index, "Missing index {$indexName} on {$table}.");
        $this->assertSame(1, (int) $index->unique, "Index {$indexName} is not unique.");
    }

    private function assertForeignKey(
        string $table,
        string $column,
        string $referencedTable,
        string $referencedColumn,
        string $onDelete,
    ): void {
        $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('{$table}')"));
        $foreignKey = $foreignKeys->first(
            fn (object $candidate): bool => $candidate->from === $column
                && $candidate->table === $referencedTable
                && $candidate->to === $referencedColumn,
        );

        $this->assertNotNull(
            $foreignKey,
            "Missing foreign key {$table}.{$column} -> {$referencedTable}.{$referencedColumn}.",
        );
        $this->assertSame($onDelete, strtoupper((string) $foreignKey->on_delete));
    }
}
