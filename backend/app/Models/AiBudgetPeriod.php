<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiBudgetPeriod extends Model
{
    protected $fillable = [
        'environment',
        'provider',
        'billing_period',
        'reserved_cost_microusd',
        'spent_cost_microusd',
    ];

    protected function casts(): array
    {
        return [
            'reserved_cost_microusd' => 'integer',
            'spent_cost_microusd' => 'integer',
        ];
    }
}
