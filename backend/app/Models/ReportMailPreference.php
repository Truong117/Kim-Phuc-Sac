<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportMailPreference extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['verified_email_hash', 'verification_email_hash', 'verification_token_hash', 'verification_key'];

    protected function casts(): array
    {
        return ['weekly_enabled' => 'boolean', 'weekly_enabled_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime',
            'verification_expires_at' => 'immutable_datetime', 'verification_requested_at' => 'immutable_datetime'];
    }
}
