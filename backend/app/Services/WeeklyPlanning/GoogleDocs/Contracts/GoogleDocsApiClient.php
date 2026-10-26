<?php

namespace App\Services\WeeklyPlanning\GoogleDocs\Contracts;

interface GoogleDocsApiClient
{
    /** @return array<string, mixed> */
    public function getDocument(
        string $documentId,
        bool $includeTabsContent,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array;
}
