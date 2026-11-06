<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailConnection extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['revision' => 0, 'status' => 'disconnected'];

    protected $hidden = ['access_token', 'refresh_token', 'provider_subject', 'configuration_hash'];

    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'expires_at' => 'datetime', 'connected_at' => 'datetime', 'revision' => 'integer'];
    }
}
