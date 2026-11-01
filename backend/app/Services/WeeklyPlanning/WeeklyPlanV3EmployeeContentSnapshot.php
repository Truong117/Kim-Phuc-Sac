<?php

namespace App\Services\WeeklyPlanning;

final readonly class WeeklyPlanV3EmployeeContentSnapshot
{
    /** @param array<string, mixed> $content */
    public function __construct(
        public array $content,
        public string $sha256,
        public int $bytes,
    ) {}
}
