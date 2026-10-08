<?php

namespace App\Enums;

enum DataScope: string
{
    case SELF = 'SELF';
    case OWN = 'OWN';
    case TEAM = 'TEAM';
    case DEPARTMENT = 'DEPARTMENT';
    case LOCATION = 'LOCATION';
    case ORGANIZATION = 'ORGANIZATION';
    case ALL = 'ALL';

    public function rank(): int
    {
        return match ($this) {
            self::SELF => 0,
            self::OWN => 1,
            self::TEAM => 2,
            self::DEPARTMENT => 3,
            self::LOCATION => 4,
            self::ORGANIZATION => 5,
            self::ALL => 6,
        };
    }
}
