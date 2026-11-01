<?php

namespace App\Enums;

enum WeeklyPlanGoogleDocsMappingStatus: string
{
    case VERIFIED = 'VERIFIED';
    case NEEDS_REVIEW = 'NEEDS_REVIEW';
    case NOT_APPLICABLE = 'NOT_APPLICABLE';
}
