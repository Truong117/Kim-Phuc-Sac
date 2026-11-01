<?php

namespace App\Enums;

enum WeeklyPlanEmployeeWorkflowAction: string
{
    case ACCEPT = 'ACCEPT';
    case REQUEST_CHANGES = 'REQUEST_CHANGES';
    case APPROVE_NO_WORK = 'APPROVE_NO_WORK';
    case HR_RESUBMIT = 'HR_RESUBMIT';
}
