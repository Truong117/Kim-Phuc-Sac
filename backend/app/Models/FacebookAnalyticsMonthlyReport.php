<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacebookAnalyticsMonthlyReport extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'closed_at' => 'immutable_datetime'];
    }
}
