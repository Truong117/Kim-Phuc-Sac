<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationGoogleDocsFolderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'folder_id',
        'organization_id',
        'actor_user_id',
        'event_type',
        'previous_google_folder_id',
        'current_google_folder_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
        ];
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(OrganizationGoogleDocsFolder::class, 'folder_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
