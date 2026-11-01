<?php

namespace App\Models\Concerns;

use LogicException;

trait HasImmutableRecords
{
    protected static function bootHasImmutableRecords(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Confirmed weekly plan review records are immutable.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Confirmed weekly plan review records are immutable.');
        });
    }
}
