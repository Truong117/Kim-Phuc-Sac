<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use App\Services\WeeklyPlanning\GoogleDocs\Contracts\ApprovedGoogleDriveFolderRegistry;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDriveFileMetadataClient;
use Throwable;

final class GoogleDocsFolderAuthorizer
{
    private const GOOGLE_DOCUMENT_MIME_TYPE = 'application/vnd.google-apps.document';

    public function __construct(
        private readonly ApprovedGoogleDriveFolderRegistry $folders,
        private readonly GoogleDriveFileMetadataClient $files,
    ) {}

    /**
     * @return array{
     *     document_id: string,
     *     approved_folder_id: string,
     *     mime_type: string,
     *     parents: list<string>
     * }
     */
    public function assertDocumentApproved(int $organizationId, string $documentId): array
    {
        if ($organizationId <= 0) {
            throw new GoogleDocsAuthorizationException('GOOGLE_DOCS_NOT_APPROVED');
        }

        $folderId = $this->folders->approvedFolderIdForOrganization($organizationId);
        if (! is_string($folderId) || trim($folderId) === '') {
            throw new GoogleDocsAuthorizationException('GOOGLE_DOCS_NOT_APPROVED');
        }

        try {
            $documentId = GoogleDocsDocumentReference::validateDocumentId($documentId);
            $folderId = GoogleDocsDocumentReference::validateDocumentId($folderId);
            $metadata = $this->files->getFileMetadata(
                $documentId,
                max(1, (int) config('google_docs.timeout_seconds', 15)),
                max(1, (int) config('google_docs.metadata_max_response_bytes', 65536)),
            );
        } catch (GoogleDriveApiException $exception) {
            throw new GoogleDocsAuthorizationException(
                'GOOGLE_DOCS_AUTHORIZATION_UNAVAILABLE',
                $exception->statusCode,
                $exception->reason,
            );
        } catch (Throwable) {
            throw new GoogleDocsAuthorizationException('GOOGLE_DOCS_AUTHORIZATION_UNAVAILABLE');
        }

        $parents = $metadata['parents'] ?? null;
        if (($metadata['id'] ?? null) !== $documentId
            || ($metadata['mime_type'] ?? null) !== self::GOOGLE_DOCUMENT_MIME_TYPE
            || ! is_array($parents)
            || ! array_is_list($parents)
            || $parents !== [$folderId]) {
            throw new GoogleDocsAuthorizationException('GOOGLE_DOCS_NOT_APPROVED');
        }

        sort($parents, SORT_STRING);

        return [
            'document_id' => $documentId,
            'approved_folder_id' => $folderId,
            'mime_type' => $metadata['mime_type'],
            'parents' => $parents,
        ];
    }
}
