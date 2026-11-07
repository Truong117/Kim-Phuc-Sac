<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FacebookAnalyticsState extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['cursor', 'lease_token'];

    protected function casts(): array
    {
        return ['cursor' => 'encrypted', 'counts' => 'array', 'catalogue_complete' => 'boolean', 'leased_until' => 'immutable_datetime', 'catalogued_at' => 'immutable_datetime', 'sampled_at' => 'immutable_datetime'];
    }
}
