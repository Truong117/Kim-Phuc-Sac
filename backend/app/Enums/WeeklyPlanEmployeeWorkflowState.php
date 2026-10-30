<?php

namespace App\Enums;

enum WeeklyPlanEmployeeWorkflowState: string
{
    case AWAITING_ASSISTANT = 'AWAITING_ASSISTANT';
    case STAGED_FOR_OWNER = 'STAGED_FOR_OWNER';
    case HR_CORRECTION = 'HR_CORRECTION';
    case PUBLISHED = 'PUBLISHED';
    case NO_WORK_APPROVED = 'NO_WORK_APPROVED';
}
