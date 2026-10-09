<?php

namespace App\Enums;

enum DashboardMode: string
{
    case ORGANIZATION = 'organization';
    case DEPARTMENT = 'department';
    case LOCATION = 'location';
    case PERSONAL = 'personal';
}
