<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkReportAnalysis extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'snapshot' => 'encrypted:array', 'analysis_config' => 'encrypted:array',
            'result' => 'encrypted:array', 'start_date' => 'immutable_date:Y-m-d',
            'end_date' => 'immutable_date:Y-m-d', 'captured_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function units(): HasMany
    {
        return $this->hasMany(WorkReportAnalysisUnit::class)->orderBy('id');
    }
}
