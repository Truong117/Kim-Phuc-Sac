<?php

namespace App\Enums;

enum WeeklyPlanPublicationPhase: string
{
    case NOT_STARTED = 'NOT_STARTED';
    case IN_REVIEW = 'IN_REVIEW';
    case PARTIALLY_PUBLISHED = 'PARTIALLY_PUBLISHED';
    case FULLY_PUBLISHED = 'FULLY_PUBLISHED';
}
