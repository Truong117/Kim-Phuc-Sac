<?php

namespace App\Support;

final class TidbStagingSchemaAssessment
{
    private const EMPTY_DEFAULT_SCHEMA = 'test';

    /**
     * @param  list<string>  $userSchemas
     * @param  array<string, int>  $objectCountsBySchema
     * @param  array<string, int>  $rowCountsBySchema
     * @return array{
     *     schema_names: list<string>,
     *     allowed_empty_default_schema_names: list<string>,
     *     unexpected_schema_names: list<string>,
     *     violations: list<string>,
     *     warnings: list<string>,
     *     evidence: string
     * }
     */
    public static function assess(
        array $userSchemas,
        array $objectCountsBySchema,
        array $rowCountsBySchema,
    ): array {
        $schemaNames = self::uniqueSchemaNames($userSchemas);
        $normalizedObjectCounts = self::normalizeCounts($objectCountsBySchema);
        $normalizedRowCounts = self::normalizeCounts($rowCountsBySchema);
        $allowedEmptyDefaultSchemas = [];
        $unexpectedSchemas = [];
        $violations = [];

        foreach ($schemaNames as $schemaName) {
            $normalizedSchemaName = self::normalize($schemaName);

            if ($schemaName !== self::EMPTY_DEFAULT_SCHEMA) {
                $unexpectedSchemas[] = $schemaName;

                continue;
            }

            if (($normalizedObjectCounts[$normalizedSchemaName] ?? 0) === 0
                && ($normalizedRowCounts[$normalizedSchemaName] ?? 0) === 0) {
                $allowedEmptyDefaultSchemas[] = $schemaName;

                continue;
            }

            $violations[] = 'default_test_schema_not_empty';
        }

        if ($unexpectedSchemas !== []) {
            $violations[] = 'user_schema_already_exists';
        }

        $warnings = $allowedEmptyDefaultSchemas === []
            ? []
            : ['empty_default_test_schema_present'];

        $evidence = match (true) {
            $unexpectedSchemas !== [] => 'unexpected_user_schema_detected',
            in_array('default_test_schema_not_empty', $violations, true) => 'default_test_schema_not_empty',
            $allowedEmptyDefaultSchemas !== [] => 'empty_default_test_schema_only',
            default => 'no_user_schema_detected',
        };

        return [
            'schema_names' => $schemaNames,
            'allowed_empty_default_schema_names' => $allowedEmptyDefaultSchemas,
            'unexpected_schema_names' => $unexpectedSchemas,
            'violations' => array_values(array_unique($violations)),
            'warnings' => $warnings,
            'evidence' => $evidence,
        ];
    }

    /**
     * @param  list<string>  $schemaNames
     * @return list<string>
     */
    private static function uniqueSchemaNames(array $schemaNames): array
    {
        $unique = [];

        foreach ($schemaNames as $schemaName) {
            $schemaName = trim($schemaName);

            if ($schemaName === '') {
                continue;
            }

            $unique[self::normalize($schemaName)] ??= $schemaName;
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private static function normalizeCounts(array $counts): array
    {
        $normalized = [];

        foreach ($counts as $schemaName => $count) {
            $normalized[self::normalize($schemaName)] = max(0, $count);
        }

        return $normalized;
    }

    private static function normalize(string $value): string
    {
        return mb_strtolower(trim($value, " \t\n\r\0\x0B\"'"));
    }
}
