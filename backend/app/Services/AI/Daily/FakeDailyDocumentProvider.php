<?php

namespace App\Services\AI\Daily;

use App\Services\AI\WorkReportGroupedWork;

class FakeDailyDocumentProvider
{
    public function analyze(array $input): array
    {
        // Fixture mode preserves the inventory without inventing an AI review or quantities.
        return ['observations' => [], 'comments' => [], 'recommendations' => [], 'work_groups' => WorkReportGroupedWork::fake([$input])];
    }
}
