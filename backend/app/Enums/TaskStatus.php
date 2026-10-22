<?php

namespace App\Enums;

enum TaskStatus: string
{
    case TODO = 'TODO';
    case IN_PROGRESS = 'IN_PROGRESS';
    case BLOCKED = 'BLOCKED';
    case COMPLETED = 'COMPLETED';
}
