<?php

namespace App\Enums;

enum WeeklyPlanReviewRoundStatus: string
{
    case OPEN = 'OPEN';
    case CHANGES_REQUESTED = 'CHANGES_REQUESTED';
    case COMPLETED = 'COMPLETED';
    case SUPERSEDED = 'SUPERSEDED';
}
