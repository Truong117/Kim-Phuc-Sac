<?php

namespace Tests\Unit;

use App\Support\TidbStagingSchemaAssessment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TidbStagingSchemaAssessmentTest extends TestCase
{
    public function test_empty_default_test_schema_is_allowed_with_an_explicit_warning(): void
    {
        $assessment = TidbStagingSchemaAssessment::assess(
            ['test'],
            ['test' => 0],
            ['test' => 0],
        );

        $this->assertSame([], $assessment['violations']);
        $this->assertSame(['empty_default_test_schema_present'], $assessment['warnings']);
        $this->assertSame(['test'], $assessment['allowed_empty_default_schema_names']);
        $this->assertSame([], $assessment['unexpected_schema_names']);
        $this->assertSame('empty_default_test_schema_only', $assessment['evidence']);
    }

    public function test_instance_without_user_schemas_remains_allowed_without_warning(): void
    {
        $assessment = TidbStagingSchemaAssessment::assess([], [], []);

        $this->assertSame([], $assessment['violations']);
        $this->assertSame([], $assessment['warnings']);
        $this->assertSame('no_user_schema_detected', $assessment['evidence']);
    }

    #[DataProvider('unsafeSchemaProvider')]
    public function test_any_other_or_non_empty_schema_fails_closed(
        array $schemas,
        array $tableCounts,
        array $rowCounts,
        string $expectedViolation,
        string $expectedEvidence,
    ): void {
        $assessment = TidbStagingSchemaAssessment::assess($schemas, $tableCounts, $rowCounts);

        $this->assertContains($expectedViolation, $assessment['violations']);
        $this->assertSame($expectedEvidence, $assessment['evidence']);
    }

    /**
     * @return array<string, array{list<string>, array<string, int>, array<string, int>, string, string}>
     */
    public static function unsafeSchemaProvider(): array
    {
        return [
            'unknown empty schema' => [
                ['sandbox'],
                ['sandbox' => 0],
                ['sandbox' => 0],
                'user_schema_already_exists',
                'unexpected_user_schema_detected',
            ],
            'similar name is not the exact default schema' => [
                ['Test'],
                ['Test' => 0],
                ['Test' => 0],
                'user_schema_already_exists',
                'unexpected_user_schema_detected',
            ],
            'application schema is never allowlisted' => [
                ['kps_internal'],
                ['kps_internal' => 0],
                ['kps_internal' => 0],
                'user_schema_already_exists',
                'unexpected_user_schema_detected',
            ],
            'test schema with an empty table is not empty' => [
                ['test'],
                ['test' => 1],
                ['test' => 0],
                'default_test_schema_not_empty',
                'default_test_schema_not_empty',
            ],
            'test schema with rows is not empty' => [
                ['test'],
                ['test' => 1],
                ['test' => 3],
                'default_test_schema_not_empty',
                'default_test_schema_not_empty',
            ],
        ];
    }
}
