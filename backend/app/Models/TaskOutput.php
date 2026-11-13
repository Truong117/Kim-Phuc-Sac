<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaskOutput extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['completed' => 'boolean', 'first_completed_on' => 'immutable_date:Y-m-d'];
    }
}
