<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiUsageRecord extends Model
{
    protected $fillable = [
        'ai_budget_period_id',
        'organization_id',
        'requested_by_user_id',
        'feature',
        'request_fingerprint',
        'status',
        'provider',
        'model',
        'reservation_version',
        'attempts',
        'input_tokens',
        'output_tokens',
        'reserved_cost_microusd',
        'estimated_cost_microusd',
        'duration_ms',
        'error_code',
        'response_payload',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'response_payload' => 'encrypted:array',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'reservation_version' => 'integer',
            'attempts' => 'integer',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reserved_cost_microusd' => 'integer',
            'estimated_cost_microusd' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    public function budgetPeriod(): BelongsTo
    {
        return $this->belongsTo(AiBudgetPeriod::class, 'ai_budget_period_id');
    }
}
