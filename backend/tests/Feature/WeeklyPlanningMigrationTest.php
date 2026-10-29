<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\AuthorizationFixtures;
use Tests\TestCase;

class WeeklyPlanningMigrationTest extends TestCase
{
    use AuthorizationFixtures;
    use RefreshDatabase;

    /** @var list<string> */
    private const WEEKLY_TABLES = [
        'weekly_plans',
        'weekly_plan_entries',
        'weekly_plan_employee_reviews',
        'weekly_plan_comments',
        'weekly_plan_suggestions',
        'weekly_plan_events',
        'weekly_plan_source_passages',
        'weekly_plan_entry_source_passage',
        'weekly_plan_entry_applications',
        'weekly_plan_entry_items',
        'weekly_plan_entry_item_source_passage',
        'weekly_plan_review_rounds',
        'weekly_plan_review_decisions',
    ];

    public function test_fresh_schema_contains_weekly_planning_tables_indexes_and_foreign_keys(): void
    {
        foreach (self::WEEKLY_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing weekly planning table: {$table}");
        }

        $this->assertTrue(Schema::hasColumns('weekly_plans', [
            'organization_id',
            'created_by',
            'week_start',
            'week_end',
            'status',
            'source_sha256',
            'parse_status',
            'uploaded_at',
            'submitted_by',
            'approved_by',
            'published_by',
        ]));
        $this->assertTrue(Schema::hasColumns('weekly_plan_entries', [
            'weekly_plan_id',
            'assignee_id',
            'title',
            'start_date',
            'due_date',
            'priority',
            'sort_order',
            'validation_status',
        ]));
        $this->assertTrue(Schema::hasColumn('tasks', 'weekly_plan_entry_id'));
        $this->assertTrue(Schema::hasColumns('weekly_plan_entry_items', [
            'weekly_plan_entry_id',
            'title',
            'content',
            'sort_order',
            'source_payload',
        ]));

        $this->assertUniqueIndex('weekly_plans', 'weekly_plans_org_week_unique');
        $this->assertUniqueIndex('weekly_plan_employee_reviews', 'weekly_reviews_plan_assignee_unique');
        $this->assertUniqueIndex('weekly_plan_source_passages', 'weekly_passages_plan_source_key_unique');
        $this->assertUniqueIndex('weekly_plan_source_passages', 'weekly_passages_plan_source_sequence_unique');
        $this->assertUniqueIndex('weekly_plan_entry_applications', 'weekly_applications_plan_key_unique');
        $this->assertUniqueIndex('tasks', 'tasks_weekly_plan_entry_unique');
        $this->assertUniqueIndex('weekly_plan_review_rounds', 'weekly_review_rounds_plan_stage_number_unique');
        $this->assertUniqueIndex('weekly_plan_review_decisions', 'weekly_review_decisions_round_assignee_unique');

        $this->assertForeignKey('weekly_plans', 'organization_id', 'organizations', 'id', 'RESTRICT');
        $this->assertForeignKey('weekly_plans', 'created_by', 'users', 'id', 'RESTRICT');
        $this->assertForeignKey('weekly_plans', 'submitted_by', 'users', 'id', 'SET NULL');
        $this->assertForeignKey('weekly_plans', 'approved_by', 'users', 'id', 'SET NULL');
        $this->assertForeignKey('weekly_plans', 'published_by', 'users', 'id', 'SET NULL');

        $this->assertForeignKey('weekly_plan_entries', 'weekly_plan_id', 'weekly_plans', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_entries', 'assignee_id', 'users', 'id', 'RESTRICT');

        $this->assertForeignKey('weekly_plan_employee_reviews', 'weekly_plan_id', 'weekly_plans', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_employee_reviews', 'assignee_id', 'users', 'id', 'RESTRICT');
        $this->assertForeignKey('weekly_plan_employee_reviews', 'reviewed_by', 'users', 'id', 'SET NULL');

        $this->assertForeignKey('weekly_plan_comments', 'weekly_plan_id', 'weekly_plans', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_comments', 'weekly_plan_entry_id', 'weekly_plan_entries', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_comments', 'assignee_id', 'users', 'id', 'RESTRICT');
        $this->assertForeignKey('weekly_plan_comments', 'author_id', 'users', 'id', 'RESTRICT');

        $this->assertForeignKey('weekly_plan_suggestions', 'weekly_plan_id', 'weekly_plans', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_suggestions', 'weekly_plan_entry_id', 'weekly_plan_entries', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_suggestions', 'decided_by', 'users', 'id', 'SET NULL');

        $this->assertForeignKey('weekly_plan_events', 'weekly_plan_id', 'weekly_plans', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_events', 'actor_id', 'users', 'id', 'SET NULL');
        $this->assertForeignKey('weekly_plan_source_passages', 'weekly_plan_id', 'weekly_plans', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_entry_source_passage', 'weekly_plan_entry_id', 'weekly_plan_entries', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_entry_source_passage', 'weekly_plan_source_passage_id', 'weekly_plan_source_passages', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_entry_applications', 'weekly_plan_id', 'weekly_plans', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_entry_applications', 'created_by', 'users', 'id', 'RESTRICT');
        $this->assertForeignKey('weekly_plan_entry_items', 'weekly_plan_entry_id', 'weekly_plan_entries', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_entry_item_source_passage', 'weekly_plan_entry_item_id', 'weekly_plan_entry_items', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_entry_item_source_passage', 'weekly_plan_source_passage_id', 'weekly_plan_source_passages', 'id', 'CASCADE');
        $this->assertForeignKey('tasks', 'weekly_plan_entry_id', 'weekly_plan_entries', 'id', 'RESTRICT');
        $this->assertForeignKey('weekly_plan_review_rounds', 'weekly_plan_id', 'weekly_plans', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_review_rounds', 'started_by', 'users', 'id', 'SET NULL');
        $this->assertForeignKey('weekly_plan_review_rounds', 'completed_by', 'users', 'id', 'SET NULL');
        $this->assertForeignKey('weekly_plan_review_decisions', 'weekly_plan_review_round_id', 'weekly_plan_review_rounds', 'id', 'CASCADE');
        $this->assertForeignKey('weekly_plan_review_decisions', 'assignee_id', 'users', 'id', 'RESTRICT');
        $this->assertForeignKey('weekly_plan_review_decisions', 'reviewed_by', 'users', 'id', 'SET NULL');
    }

    public function test_migration_19_normalizes_legacy_owner_plans_and_copies_reviews_without_fabricating_assistant_history(): void
    {
        $migration = $this->migration('2026_10_29_000000_create_weekly_plan_staged_review_tables.php');
        $migration->down();

        $plans = [];
        foreach ([
            'SUBMITTED' => 'LEGACY_SUBMITTED',
            'IN_REVIEW' => 'LEGACY_IN_REVIEW',
            'CHANGES_REQUESTED' => 'LEGACY_CHANGES',
        ] as $status => $organizationCode) {
            $user = User::factory()->create();
            $membership = $this->createMembership($user, organizationCode: $organizationCode);
            [$planId] = $this->insertPlanAndEntry($membership['organization_id'], $user->id);
            DB::table('weekly_plans')->where('id', $planId)->update([
                'status' => $status,
                'submitted_by' => $user->id,
                'submitted_at' => now(),
            ]);
            $plans[$status] = [$planId, $user];
        }

        [$reviewedPlanId, $reviewer] = $plans['IN_REVIEW'];
        DB::table('weekly_plan_employee_reviews')->insert([
            'weekly_plan_id' => $reviewedPlanId,
            'assignee_id' => $reviewer->id,
            'status' => 'ACCEPTED',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'comment' => 'Legacy comment remains only on the legacy row.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertDatabaseHas('weekly_plans', [
            'id' => $plans['SUBMITTED'][0],
            'status' => 'OWNER_REVIEW',
        ]);
        $this->assertDatabaseHas('weekly_plans', [
            'id' => $plans['IN_REVIEW'][0],
            'status' => 'OWNER_REVIEW',
        ]);
        $this->assertDatabaseHas('weekly_plans', [
            'id' => $plans['CHANGES_REQUESTED'][0],
            'status' => 'OWNER_CHANGES_REQUESTED',
        ]);
        $this->assertDatabaseCount('weekly_plan_review_rounds', 3);
        $this->assertDatabaseMissing('weekly_plan_review_rounds', ['stage' => 'ASSISTANT']);
        $ownerRoundId = DB::table('weekly_plan_review_rounds')
            ->where('weekly_plan_id', $reviewedPlanId)
            ->where('stage', 'OWNER')
            ->value('id');
        $this->assertDatabaseHas('weekly_plan_review_decisions', [
            'weekly_plan_review_round_id' => $ownerRoundId,
            'assignee_id' => $reviewer->id,
            'status' => 'ACCEPTED',
            'reviewed_by' => $reviewer->id,
        ]);
        $this->assertDatabaseHas('weekly_plan_employee_reviews', [
            'weekly_plan_id' => $reviewedPlanId,
            'assignee_id' => $reviewer->id,
            'status' => 'ACCEPTED',
            'comment' => 'Legacy comment remains only on the legacy row.',
        ]);
    }

    public function test_document_extraction_foreign_key_names_fit_tidb_identifier_limits(): void
    {
        $migration = file_get_contents(database_path(
            'migrations/2026_10_22_020000_create_weekly_plan_document_extraction_tables.php',
        ));
        $this->assertIsString($migration);

        foreach ([
            'weekly_entry_passage_entry_foreign',
            'weekly_entry_passage_passage_foreign',
        ] as $constraint) {
            $this->assertLessThanOrEqual(64, strlen($constraint));
            $this->assertStringContainsString("'{$constraint}'", $migration);
        }
    }

    public function test_source_constraint_preserves_existing_tasks_and_enforces_unique_and_restrict(): void
    {
        $sourceMigration = $this->migration('2026_10_22_010001_add_weekly_plan_source_to_tasks_table.php');
        $sourceMigration->down();

        [$organizationId, $user] = $this->organizationAndUser();
        $existingTaskId = $this->insertTask($organizationId, $user->id, 'Existing task');

        $sourceMigration->up();

        $this->assertDatabaseHas('tasks', ['id' => $existingTaskId, 'title' => 'Existing task']);
        $this->assertNull(DB::table('tasks')->where('id', $existingTaskId)->value('weekly_plan_entry_id'));

        [$planId, $entryId] = $this->insertPlanAndEntry($organizationId, $user->id);
        DB::table('tasks')->where('id', $existingTaskId)->update(['weekly_plan_entry_id' => $entryId]);

        try {
            $this->insertTask($organizationId, $user->id, 'Duplicate source task', $entryId);
            $this->fail('The weekly plan entry unique constraint did not reject a duplicate task.');
        } catch (QueryException) {
            $this->assertDatabaseCount('tasks', 1);
        }

        try {
            DB::table('weekly_plan_entries')->where('id', $entryId)->delete();
            $this->fail('The task source foreign key did not restrict deletion of a published entry.');
        } catch (QueryException) {
            $this->assertDatabaseHas('weekly_plan_entries', ['id' => $entryId]);
        }

        try {
            DB::table('weekly_plans')->where('id', $planId)->delete();
            $this->fail('Deleting a plan must not cascade through an entry referenced by a task.');
        } catch (QueryException) {
            $this->assertDatabaseHas('weekly_plans', ['id' => $planId]);
            $this->assertDatabaseHas('tasks', ['id' => $existingTaskId, 'weekly_plan_entry_id' => $entryId]);
        }
    }

    public function test_weekly_migrations_roll_back_in_dependency_order_without_losing_tasks(): void
    {
        [$organizationId, $user] = $this->organizationAndUser();
        [, $entryId] = $this->insertPlanAndEntry($organizationId, $user->id);
        $taskId = $this->insertTask($organizationId, $user->id, 'Published task', $entryId);

        $sourceMigration = $this->migration('2026_10_22_010001_add_weekly_plan_source_to_tasks_table.php');
        $documentMigration = $this->migration('2026_10_22_020000_create_weekly_plan_document_extraction_tables.php');
        $itemMigration = $this->migration('2026_10_28_000000_create_weekly_plan_entry_items_tables.php');
        $stagedReviewMigration = $this->migration('2026_10_29_000000_create_weekly_plan_staged_review_tables.php');
        $weeklyMigration = $this->migration('2026_10_22_010000_create_weekly_planning_tables.php');

        $stagedReviewMigration->down();
        $itemMigration->down();
        $documentMigration->down();
        $sourceMigration->down();

        $this->assertTrue(Schema::hasTable('tasks'));
        $this->assertFalse(Schema::hasColumn('tasks', 'weekly_plan_entry_id'));
        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'title' => 'Published task']);

        $weeklyMigration->down();

        foreach (self::WEEKLY_TABLES as $table) {
            $this->assertFalse(Schema::hasTable($table), "Weekly planning table was not rolled back: {$table}");
        }
        $this->assertTrue(Schema::hasTable('tasks'));
        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'title' => 'Published task']);

        $weeklyMigration->up();
        $sourceMigration->up();
        $documentMigration->up();
        $itemMigration->up();
        $stagedReviewMigration->up();

        foreach (self::WEEKLY_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Weekly planning table was not recreated: {$table}");
        }
        $this->assertTrue(Schema::hasColumn('tasks', 'weekly_plan_entry_id'));
        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'title' => 'Published task']);
        $this->assertNull(DB::table('tasks')->where('id', $taskId)->value('weekly_plan_entry_id'));
    }

    private function migration(string $filename): Migration
    {
        $migration = require database_path("migrations/{$filename}");
        $this->assertInstanceOf(Migration::class, $migration);

        return $migration;
    }

    /** @return array{int, User} */
    private function organizationAndUser(): array
    {
        $user = User::factory()->create();
        $membership = $this->createMembership($user);

        return [$membership['organization_id'], $user];
    }

    /** @return array{int, int} */
    private function insertPlanAndEntry(int $organizationId, int $userId): array
    {
        $now = now();
        $planId = DB::table('weekly_plans')->insertGetId([
            'organization_id' => $organizationId,
            'created_by' => $userId,
            'week_start' => '2026-10-26',
            'week_end' => '2026-10-31',
            'status' => 'DRAFT',
            'source_disk' => null,
            'source_path' => null,
            'original_filename' => 'migration-test.xlsx',
            'source_extension' => 'xlsx',
            'source_mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'source_size' => 1,
            'source_sha256' => str_repeat('a', 64),
            'parse_status' => 'PARSED',
            'parse_metadata' => null,
            'uploaded_at' => $now,
            'submitted_by' => null,
            'submitted_at' => null,
            'approved_by' => null,
            'approved_at' => null,
            'published_by' => null,
            'published_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $entryId = DB::table('weekly_plan_entries')->insertGetId([
            'weekly_plan_id' => $planId,
            'assignee_id' => $userId,
            'source_row' => 2,
            'employee_identifier' => 'migration@example.test',
            'employee_name' => 'Migration Test',
            'department_name' => null,
            'title' => 'Weekly source task',
            'content' => null,
            'expected_result' => null,
            'start_date' => '2026-10-26',
            'due_date' => '2026-10-31',
            'priority' => 'MEDIUM',
            'note' => null,
            'source_payload' => null,
            'sort_order' => 1,
            'validation_status' => 'VALID',
            'validation_errors' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [$planId, $entryId];
    }

    private function insertTask(
        int $organizationId,
        int $userId,
        string $title,
        ?int $weeklyPlanEntryId = null,
    ): int {
        $values = [
            'organization_id' => $organizationId,
            'creator_id' => $userId,
            'assignee_id' => $userId,
            'department_id' => null,
            'location_id' => null,
            'title' => $title,
            'description' => null,
            'priority' => 'MEDIUM',
            'status' => 'TODO',
            'start_date' => null,
            'due_date' => '2026-10-31',
            'completed_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('tasks', 'weekly_plan_entry_id')) {
            $values['weekly_plan_entry_id'] = $weeklyPlanEntryId;
        }

        return DB::table('tasks')->insertGetId($values);
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
