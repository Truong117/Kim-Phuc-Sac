<?php

namespace App\Enums;

enum WeeklyPlanReviewStatus: string
{
    case PENDING = 'PENDING';
    case CHANGES_REQUESTED = 'CHANGES_REQUESTED';
    case ACCEPTED = 'ACCEPTED';
}
