<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDriveFileMetadataClient;
use Google\Service\Drive;

final class GoogleDriveFileMetadataTransport implements GoogleDriveFileMetadataClient
{
    private const FILES_ENDPOINT = 'https://www.googleapis.com/drive/v3/files/';

    public function __construct(private readonly BoundedGoogleJsonTransport $transport) {}

    public function getFileMetadata(
        string $fileId,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array {
        $fileId = GoogleDocsDocumentReference::validateDocumentId($fileId);

        try {
            $metadata = $this->transport->get(
                self::FILES_ENDPOINT.rawurlencode($fileId),
                [Drive::DRIVE_METADATA_READONLY],
                [
                    'fields' => 'id,mimeType,parents',
                    'supportsAllDrives' => 'true',
                ],
                $timeoutSeconds,
                $maxResponseBytes,
            );
        } catch (GoogleApiTransportException $exception) {
            throw new GoogleDriveApiException(
                $exception->statusCode,
                $exception->reason,
            );
        }

        $parents = $metadata['parents'] ?? [];
        if (! is_string($metadata['id'] ?? null)
            || ! is_string($metadata['mimeType'] ?? null)
            || ! is_array($parents)
            || ! array_is_list($parents)
            || array_filter($parents, fn (mixed $parent): bool => ! is_string($parent) || $parent === '') !== []) {
            throw new GoogleDriveApiException(0, 'MALFORMED_RESPONSE');
        }

        return [
            'id' => $metadata['id'],
            'mime_type' => $metadata['mimeType'],
            'parents' => $parents,
        ];
    }
}
