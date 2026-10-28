<?php

namespace Tests\Unit;

use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StagingIncrementalMigrationInspectorTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        require_once dirname(__DIR__, 2).'/scripts/inspect-staging-incremental-migration.php';
    }

    public function test_extracts_associative_migration_names_in_row_order(): void
    {
        $this->assertSame([
            'migration_a',
            'migration_b',
        ], \incrementalFirstColumnValues([
            ['migration' => 'migration_a'],
            ['migration' => 'migration_b'],
        ]));
    }

    public function test_extracts_weekly_plan_ids_without_changing_their_values_or_order(): void
    {
        $this->assertSame([1, 7], \incrementalFirstColumnValues([
            ['id' => 1],
            ['id' => 7],
        ]));
    }

    public function test_extracts_a_dynamic_show_grants_column_without_knowing_its_name(): void
    {
        $grant = 'GRANT SELECT ON `kps_internal`.* TO `user`@`%`';

        $this->assertSame([$grant], \incrementalFirstColumnValues([
            ['Grants for user@%' => $grant],
        ]));
    }

    public function test_empty_result_returns_an_empty_list(): void
    {
        $this->assertSame([], \incrementalFirstColumnValues([]));
    }

    /** @param list<mixed> $rows */
    #[DataProvider('malformedRows')]
    public function test_malformed_rows_fail_closed(array $rows): void
    {
        $this->expectException(RuntimeException::class);

        \incrementalFirstColumnValues($rows);
    }

    /** @return array<string, array{list<mixed>}> */
    public static function malformedRows(): array
    {
        return [
            'empty row' => [[[]]],
            'non-array row' => [[null]],
            'null first value' => [[['id' => null]]],
        ];
    }

    public function test_query_column_reads_associative_rows_and_preserves_order(): void
    {
        $pdo = $this->sqlitePdo();

        $this->assertSame([
            'migration_a',
            'migration_b',
        ], \incrementalQueryColumn(
            $pdo,
            <<<'SQL'
                SELECT migration
                FROM (
                    SELECT 2 AS sequence, 'migration_b' AS migration
                    UNION ALL
                    SELECT 1 AS sequence, 'migration_a' AS migration
                )
                ORDER BY sequence
                SQL,
        ));
    }

    public function test_query_column_supports_a_dynamic_column_name_and_empty_results(): void
    {
        $pdo = $this->sqlitePdo();
        $grant = 'GRANT SELECT ON `kps_internal`.* TO `user`@`%`';

        $this->assertSame([$grant], \incrementalQueryColumn(
            $pdo,
            <<<'SQL'
                SELECT 'GRANT SELECT ON `kps_internal`.* TO `user`@`%`' AS "Grants for user@%"
                SQL,
        ));
        $this->assertSame([], \incrementalQueryColumn($pdo, 'SELECT 1 AS id WHERE 0 = 1'));
    }

    public function test_query_errors_are_not_converted_to_empty_results(): void
    {
        $this->expectException(PDOException::class);

        \incrementalQueryColumn($this->sqlitePdo(), 'SELECT id FROM missing_table');
    }

    private function sqlitePdo(): PDO
    {
        return new PDO('sqlite::memory:', options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
