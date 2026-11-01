<?php

namespace App\Enums;

enum WeeklyPlanSourceType: string
{
    case UPLOADED_FILE = 'UPLOADED_FILE';
    case GOOGLE_DOCS = 'GOOGLE_DOCS';
}
