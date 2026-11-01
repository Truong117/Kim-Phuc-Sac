<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyPlanGoogleDocsTabMappingEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'mapping_id',
        'organization_id',
        'weekly_plan_id',
        'actor_user_id',
        'previous_status',
        'new_status',
        'previous_assignee_user_id',
        'new_assignee_user_id',
        'tab_title_sha256',
        'mapping_version',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'mapping_version' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function mapping(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlanGoogleDocsTabMapping::class, 'mapping_id');
    }
}
