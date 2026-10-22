<?php

namespace App\Enums;

enum WeeklyPlanStatus: string
{
    case DRAFT = 'DRAFT';
    case SUBMITTED = 'SUBMITTED';
    case IN_REVIEW = 'IN_REVIEW';
    case CHANGES_REQUESTED = 'CHANGES_REQUESTED';
    case APPROVED = 'APPROVED';
    case PUBLISHED = 'PUBLISHED';
}
