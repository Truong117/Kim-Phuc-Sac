<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use RuntimeException;
use Throwable;

final class GoogleDocsAuthorizationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $providerStatus = 0,
        public readonly ?string $providerReason = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Google document is not approved for this organization.', 0, $previous);
    }
}
