<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SocialConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['access_token', 'lease_token'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'sync_counts' => 'array', 'leased_until' => 'immutable_datetime', 'synced_at' => 'immutable_datetime'];
    }
}
