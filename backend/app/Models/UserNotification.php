<?php

namespace App\Models;

use App\Enums\NotificationReferenceType;
use App\Enums\UserNotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserNotification extends Model
{
    protected $fillable = [
        'recipient_user_id',
        'actor_user_id',
        'type',
        'reference_type',
        'reference_id',
        'title',
        'message',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => UserNotificationType::class,
            'reference_type' => NotificationReferenceType::class,
            'reference_id' => 'integer',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
