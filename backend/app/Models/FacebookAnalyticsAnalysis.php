<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacebookAnalyticsAnalysis extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'result' => 'encrypted:array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
