<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReportHoliday extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'organization_id', 'name', 'start_date', 'end_date', 'note', 'is_active',
        'version', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['start_date' => 'immutable_date:Y-m-d', 'end_date' => 'immutable_date:Y-m-d', 'is_active' => 'boolean', 'version' => 'integer'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** @return array{name: string, note: ?string} */
    public function notice(): array
    {
        return ['name' => $this->name, 'note' => $this->note];
    }
}
