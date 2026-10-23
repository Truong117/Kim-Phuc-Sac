<?php

namespace App\Models;

use App\Enums\WeeklyPlanParseStatus;
use App\Enums\WeeklyPlanStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WeeklyPlan extends Model
{
    protected $fillable = [
        'organization_id',
        'created_by',
        'week_start',
        'week_end',
        'status',
        'source_disk',
        'source_path',
        'original_filename',
        'source_extension',
        'source_mime',
        'source_size',
        'source_sha256',
        'parse_status',
        'parse_metadata',
        'uploaded_at',
        'submitted_by',
        'submitted_at',
        'approved_by',
        'approved_at',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'immutable_date:Y-m-d',
            'week_end' => 'immutable_date:Y-m-d',
            'status' => WeeklyPlanStatus::class,
            'parse_status' => WeeklyPlanParseStatus::class,
            'parse_metadata' => 'array',
            'uploaded_at' => 'immutable_datetime',
            'submitted_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(WeeklyPlanEntry::class)->orderBy('sort_order');
    }

    public function employeeReviews(): HasMany
    {
        return $this->hasMany(WeeklyPlanEmployeeReview::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(WeeklyPlanComment::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function suggestions(): HasMany
    {
        return $this->hasMany(WeeklyPlanSuggestion::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WeeklyPlanEvent::class)
            ->orderBy('created_at')
            ->orderBy('id');
    }

    public function sourcePassages(): HasMany
    {
        return $this->hasMany(WeeklyPlanSourcePassage::class)
            ->orderBy('sequence');
    }

    public function entryApplications(): HasMany
    {
        return $this->hasMany(WeeklyPlanEntryApplication::class);
    }
}
