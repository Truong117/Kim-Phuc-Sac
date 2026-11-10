<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportAutomationCycle extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['mail_content'];

    protected function casts(): array
    {
        return ['end_date' => 'immutable_date:Y-m-d', 'due_at' => 'immutable_datetime',
            'source_closes_at' => 'immutable_datetime', 'mail_due_at' => 'immutable_datetime',
            'next_check_at' => 'immutable_datetime', 'finalized_at' => 'immutable_datetime',
            'mail_frozen_at' => 'immutable_datetime', 'mail_content' => 'encrypted:array'];
    }
}
