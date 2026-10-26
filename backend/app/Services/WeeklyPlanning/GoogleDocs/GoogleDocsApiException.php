<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use RuntimeException;
use Throwable;

final class GoogleDocsApiException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Google Docs request failed.', 0, $previous);
    }
}
