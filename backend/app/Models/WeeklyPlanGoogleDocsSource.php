<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklyPlanGoogleDocsSource extends Model
{
    protected $fillable = [
        'weekly_plan_id',
        'organization_id',
        'approved_folder_id',
        'confirmed_by_user_id',
        'request_id',
        'request_payload_sha256',
        'google_document_id',
        'document_id_sha256',
        'approved_folder_id_sha256',
        'document_title',
        'revision_id',
        'snapshot_sha256',
        'tab_manifest',
        'passage_count',
        'character_count',
        'captured_at',
        'last_verified_at',
        'stale_at',
    ];

    protected $hidden = [
        'organization_id',
        'approved_folder_id',
        'confirmed_by_user_id',
        'request_id',
        'google_document_id',
        'document_id_sha256',
        'approved_folder_id_sha256',
        'request_payload_sha256',
    ];

    protected function casts(): array
    {
        return [
            'google_document_id' => 'encrypted',
            'document_title' => 'encrypted',
            'revision_id' => 'encrypted',
            'tab_manifest' => 'encrypted:array',
            'passage_count' => 'integer',
            'character_count' => 'integer',
            'captured_at' => 'immutable_datetime',
            'last_verified_at' => 'immutable_datetime',
            'stale_at' => 'immutable_datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WeeklyPlan::class, 'weekly_plan_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function approvedFolder(): BelongsTo
    {
        return $this->belongsTo(OrganizationGoogleDocsFolder::class, 'approved_folder_id');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by_user_id');
    }
}
