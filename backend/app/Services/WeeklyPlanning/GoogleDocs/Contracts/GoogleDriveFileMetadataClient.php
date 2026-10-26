<?php

namespace App\Services\WeeklyPlanning\GoogleDocs\Contracts;

interface GoogleDriveFileMetadataClient
{
    /** @return array{id: string, mime_type: string, parents: list<string>} */
    public function getFileMetadata(
        string $fileId,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array;
}
