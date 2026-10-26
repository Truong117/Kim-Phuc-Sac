<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use RuntimeException;
use Throwable;

final class GoogleDocsReadException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $retryable = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Google Docs could not be read safely.', 0, $previous);
    }
}
