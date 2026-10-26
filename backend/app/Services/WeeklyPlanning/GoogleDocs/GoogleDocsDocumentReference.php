<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

final class GoogleDocsDocumentReference
{
    private const DOCUMENT_ID_PATTERN = '/^[A-Za-z0-9_-]{20,200}$/D';

    public static function documentIdFromUrl(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== 'docs.google.com'
            || isset($parts['user'])
            || isset($parts['pass'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_INVALID_REFERENCE');
        }

        $path = (string) ($parts['path'] ?? '');
        if (preg_match('#^/document/d/([^/]+)(?:/.*)?$#D', $path, $matches) !== 1) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_INVALID_REFERENCE');
        }

        return self::validateDocumentId($matches[1]);
    }

    public static function validateDocumentId(string $documentId): string
    {
        $documentId = trim($documentId);
        if (preg_match(self::DOCUMENT_ID_PATTERN, $documentId) !== 1) {
            throw new GoogleDocsReadException('GOOGLE_DOCS_INVALID_DOCUMENT_ID');
        }

        return $documentId;
    }
}
