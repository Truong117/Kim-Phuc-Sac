<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class MailMessage extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'available_at' => 'datetime', 'started_at' => 'datetime', 'accepted_at' => 'datetime', 'attempts' => 'integer', 'connection_revision' => 'integer'];
    }
}
