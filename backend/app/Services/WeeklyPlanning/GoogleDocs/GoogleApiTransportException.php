<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use RuntimeException;
use Throwable;

final class GoogleApiTransportException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        public readonly ?string $reason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Google API request failed safely.', 0, $previous);
    }
}
