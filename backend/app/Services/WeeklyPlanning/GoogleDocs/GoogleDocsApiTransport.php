<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDocsApiClient;
use Google\Service\Docs;

final class GoogleDocsApiTransport implements GoogleDocsApiClient
{
    private const DOCUMENTS_ENDPOINT = 'https://docs.googleapis.com/v1/documents/';

    public function __construct(private readonly BoundedGoogleJsonTransport $transport) {}

    public function getDocument(
        string $documentId,
        bool $includeTabsContent,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array {
        $documentId = GoogleDocsDocumentReference::validateDocumentId($documentId);
        if (! $includeTabsContent) {
            throw new GoogleDocsApiException(0, 'INVALID_REQUEST');
        }

        try {
            return $this->transport->get(
                self::DOCUMENTS_ENDPOINT.rawurlencode($documentId),
                [Docs::DOCUMENTS_READONLY],
                ['includeTabsContent' => 'true'],
                $timeoutSeconds,
                $maxResponseBytes,
            );
        } catch (GoogleApiTransportException $exception) {
            throw new GoogleDocsApiException(
                $exception->statusCode,
                $exception->reason,
            );
        }
    }
}
