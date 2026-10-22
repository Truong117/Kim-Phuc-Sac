<?php

namespace App\Enums;

enum WeeklyPlanParseStatus: string
{
    case PARSED = 'PARSED';
    case NEEDS_MAPPING = 'NEEDS_MAPPING';
    case FAILED = 'FAILED';
}
