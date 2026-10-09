<?php

namespace App\Enums;

enum WorkStatus: string
{
    case COMPLETED = 'COMPLETED';
    case IN_PROGRESS = 'IN_PROGRESS';
    case BLOCKED = 'BLOCKED';
}
