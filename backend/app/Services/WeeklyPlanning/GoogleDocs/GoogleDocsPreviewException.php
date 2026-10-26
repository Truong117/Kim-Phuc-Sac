<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use RuntimeException;

final class GoogleDocsPreviewException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $safeMessage,
    ) {
        parent::__construct($safeMessage);
    }
}
