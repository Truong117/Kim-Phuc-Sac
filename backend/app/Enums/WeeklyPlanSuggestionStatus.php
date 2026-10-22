<?php

namespace App\Enums;

enum WeeklyPlanSuggestionStatus: string
{
    case PENDING = 'PENDING';
    case ACCEPTED = 'ACCEPTED';
    case REJECTED = 'REJECTED';
}
