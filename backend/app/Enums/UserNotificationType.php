<?php

namespace App\Enums;

enum UserNotificationType: string
{
    case REPORT_COMMENT = 'REPORT_COMMENT';
    case TASK_ASSIGNED = 'TASK_ASSIGNED';
    case TASK_REASSIGNED = 'TASK_REASSIGNED';
}
