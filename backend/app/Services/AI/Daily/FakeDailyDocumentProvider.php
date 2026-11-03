<?php

namespace App\Services\AI\Daily;

class FakeDailyDocumentProvider
{
    public function analyze(array $input): array
    {
        // Fixture mode preserves the inventory without inventing an AI review or quantities.
        return ['observations' => [], 'comments' => [], 'recommendations' => []];
    }
}
