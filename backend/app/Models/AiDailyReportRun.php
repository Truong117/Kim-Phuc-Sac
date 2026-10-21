<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiDailyReportRun extends Model
{
    protected $fillable = [
        'organization_id', 'requested_by_user_id', 'report_date', 'status',
        'source_report_ids', 'work_counts', 'result', 'provider', 'model',
        'input_tokens', 'output_tokens', 'error_code', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'immutable_date:Y-m-d',
            'source_report_ids' => 'array',
            'work_counts' => 'array',
            'result' => 'array',
            'completed_at' => 'immutable_datetime',
        ];
    }
}
