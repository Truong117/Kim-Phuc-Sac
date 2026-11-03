<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DailyReportAiDocument extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['snapshot' => 'encrypted:array', 'analysis_config' => 'encrypted:array',
            'result' => 'encrypted:array', 'report_date' => 'immutable_date:Y-m-d',
            'captured_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function units(): HasMany
    {
        return $this->hasMany(DailyReportAiUnit::class)->orderBy('part');
    }
}
