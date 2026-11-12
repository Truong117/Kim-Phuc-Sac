<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PersonalWeeklyMailDelivery extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['email_hash'];

    protected function casts(): array
    {
        return ['end_date' => 'immutable_date:Y-m-d'];
    }
}
