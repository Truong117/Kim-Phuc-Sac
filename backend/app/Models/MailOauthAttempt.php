<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailOauthAttempt extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['state_hash', 'configuration_hash', 'code_verifier'];

    protected function casts(): array
    {
        return ['code_verifier' => 'encrypted', 'expires_at' => 'datetime', 'consumed_at' => 'datetime', 'connection_revision' => 'integer'];
    }
}
