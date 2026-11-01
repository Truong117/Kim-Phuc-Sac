<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkReportAnalysisUnit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['input' => 'encrypted:array', 'result' => 'encrypted:array', 'leased_until' => 'immutable_datetime'];
    }
}
