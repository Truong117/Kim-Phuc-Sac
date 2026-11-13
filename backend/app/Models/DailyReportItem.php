<?php

namespace App\Models;

use App\Enums\WorkStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyReportItem extends Model
{
    protected $fillable = [
        'content',
        'result',
        'status',
        'note',
        'issue',
        'evidence_links',
        'facebook_selections',
        'sort_order',
        'task_id',
        'task_snapshot',
        'task_progress',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkStatus::class,
            'sort_order' => 'integer',
            'evidence_links' => 'array',
            'facebook_selections' => 'array',
            'task_snapshot' => 'array',
            'task_progress' => 'array',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DailyReport::class, 'daily_report_id');
    }
}
