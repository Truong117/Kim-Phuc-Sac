<?php

namespace App\Enums;

enum WeeklyPlanV3ReviewDecisionType: string
{
    case CHANGES_REQUESTED = 'CHANGES_REQUESTED';
    case ACCEPTED = 'ACCEPTED';
    case APPROVE_NO_WORK = 'APPROVE_NO_WORK';
}
