<?php

namespace App\Services\WeeklyPlanning;

use DomainException;

final class WeeklyPlanEmployeeLifecycleConflictException extends DomainException
{
    public const INVALID_WORKFLOW_VERSION = 'INVALID_WORKFLOW_VERSION';

    public const WORKFLOW_PLAN_MISMATCH = 'WORKFLOW_PLAN_MISMATCH';

    public const DUPLICATE_ASSIGNEE = 'DUPLICATE_ASSIGNEE';

    public const ASSIGNEE_ORGANIZATION_MISMATCH = 'ASSIGNEE_ORGANIZATION_MISMATCH';

    public const STALE_STATE_VERSION = 'STALE_STATE_VERSION';

    public const STALE_CONTENT_VERSION = 'STALE_CONTENT_VERSION';

    public const TERMINAL_STATE = 'TERMINAL_STATE';

    public const REVIEW_STAGE_MISMATCH = 'REVIEW_STAGE_MISMATCH';

    public const INVALID_TRANSITION = 'INVALID_TRANSITION';

    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
