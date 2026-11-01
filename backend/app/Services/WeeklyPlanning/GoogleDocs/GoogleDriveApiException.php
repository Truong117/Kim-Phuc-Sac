<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use RuntimeException;
use Throwable;

final class GoogleDriveApiException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Google Drive metadata request failed.', 0, $previous);
    }
}
