<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::updating(function (User $user): void {
            if ($user->isDirty('email') && Schema::hasTable('report_mail_preferences')) {
                // Invalidate even an A -> B -> A address change, including administrator edits.
                DB::table('report_mail_preferences')->where('user_id', $user->id)->update([
                    'weekly_enabled' => false, 'weekly_enabled_at' => null, 'verified_email_hash' => null, 'verified_at' => null,
                    'verification_key' => null, 'verification_email_hash' => null, 'verification_token_hash' => null,
                    'verification_expires_at' => null, 'verification_requested_at' => null, 'verification_mail_message_id' => null,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Verification belongs to the previous address and must not carry over.
    public function setEmailAttribute(string $email): void
    {
        if (($this->attributes['email'] ?? null) !== $email) {
            $this->attributes['email_verified_at'] = null;
        }

        $this->attributes['email'] = $email;
    }

    public function organizationMemberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function defaultOrganizationMembership(): HasOne
    {
        return $this->hasOne(OrganizationMembership::class)
            ->where('is_default', true);
    }

    public function dailyReports(): HasMany
    {
        return $this->hasMany(DailyReport::class);
    }

    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'creator_id');
    }

    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    public function createdWeeklyPlans(): HasMany
    {
        return $this->hasMany(WeeklyPlan::class, 'created_by');
    }

    public function receivedNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'recipient_user_id');
    }
}
