<?php

namespace App\Models;

use App\Enums\WeeklyPlanGoogleDocsMappingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyPlanGoogleDocsTabMapping extends Model
{
    protected $fillable = [
        'organization_id',
        'weekly_plan_id',
        'document_id_sha256',
        'tab_id_sha256',
        'tab_title_sha256',
        'status',
        'assignee_user_id',
        'verified_by_user_id',
        'verified_at',
        'version',
    ];

    protected $hidden = [
        'organization_id',
        'weekly_plan_id',
        'document_id_sha256',
        'tab_id_sha256',
        'tab_title_sha256',
    ];

    protected function casts(): array
    {
        return [
            'status' => WeeklyPlanGoogleDocsMappingStatus::class,
            'verified_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class, 'weekly_plan_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_user_id');
    }

    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(WeeklyPlanGoogleDocsTabMappingEvent::class, 'mapping_id');
    }
}
