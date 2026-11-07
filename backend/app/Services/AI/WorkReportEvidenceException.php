<?php

namespace App\Services\AI;

use RuntimeException;

/** Fixed diagnostics only: never include source text or provider output. */
final class WorkReportEvidenceException extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly string $location)
    {
        parent::__construct('AI_INVALID_EVIDENCE');
    }
}
