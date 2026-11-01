<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkReportAnalysisRevision extends Model
{
    protected $primaryKey = 'analysis_id';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['review_feedback' => 'encrypted'];
    }
}
