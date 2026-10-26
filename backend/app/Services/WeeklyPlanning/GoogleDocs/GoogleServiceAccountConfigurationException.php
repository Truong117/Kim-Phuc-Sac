<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use RuntimeException;
use Throwable;

final class GoogleServiceAccountConfigurationException extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('Google service account is not safely configured.', 0, $previous);
    }
}
