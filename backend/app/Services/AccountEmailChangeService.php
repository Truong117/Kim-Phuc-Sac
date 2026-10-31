<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\AccountEmailChanged;
use App\Notifications\VerifyAccountEmailChange;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

class AccountEmailChangeService
{
    /** @return array{email_verified: bool, pending: array{email: string, expires_at: string}|null} */
    public function state(User $actor): array
    {
        $user = $actor->fresh();
        $pending = DB::table('account_email_changes')->where('user_id', $user->id)->first();
        $valid = $pending !== null
            && $pending->expires_at > now()->toDateTimeString()
            && $pending->original_email === $user->email
            && hash_equals($pending->password_fingerprint, hash('sha256', $user->password));

        return [
            'email_verified' => $user->email_verified_at !== null,
            'pending' => $valid ? [
                'email' => $pending->email,
                'expires_at' => Carbon::parse($pending->expires_at)->toISOString(),
            ] : null,
        ];
    }

    public function request(User $actor, string $email, string $password): void
    {
        DB::transaction(function () use ($actor, $email, $password): void {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $this->confirmPassword($user, $password);
            $token = bin2hex(random_bytes(32));

            // Deliberately no email availability response: ownership must be proven first.
            DB::table('account_email_changes')->updateOrInsert(['user_id' => $user->id], [
                'email' => $email,
                'original_email' => $user->email,
                'password_fingerprint' => hash('sha256', $user->password),
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addMinutes((int) config('account.email_change_minutes')),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            Notification::route('mail', $email)->notify(new VerifyAccountEmailChange($token));
        });
    }

    public function confirm(User $actor, string $token, string $password): User
    {
        try {
            return DB::transaction(function () use ($actor, $token, $password): User {
                $user = User::query()->lockForUpdate()->findOrFail($actor->id);
                $this->confirmPassword($user, $password);
                $pending = DB::table('account_email_changes')
                    ->where('user_id', $user->id)->lockForUpdate()->first();

                if ($pending === null
                    || $pending->expires_at <= now()->toDateTimeString()
                    || ! hash_equals($pending->token_hash, hash('sha256', $token))
                    || $pending->original_email !== $user->email
                    || ! hash_equals($pending->password_fingerprint, hash('sha256', $user->password))
                    || strcasecmp($pending->email, $user->email) === 0
                    || User::query()->whereKeyNot($user->id)
                        ->whereRaw('LOWER(email) = ?', [strtolower($pending->email)])->exists()) {
                    $this->invalidConfirmation();
                }

                $oldEmail = $user->email;
                $user->email = $pending->email;
                $user->email_verified_at = now();
                $user->save();
                DB::table('account_email_changes')->where('user_id', $user->id)->delete();

                Notification::route('mail', $oldEmail)->notify(new AccountEmailChanged);

                return $user->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            $this->invalidConfirmation();
        }
    }

    public function cancel(User $actor): void
    {
        DB::transaction(function () use ($actor): void {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            DB::table('account_email_changes')->where('user_id', $actor->id)->delete();
        });
    }

    private function confirmPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mật khẩu hiện tại không chính xác.'],
            ]);
        }
    }

    private function invalidConfirmation(): never
    {
        throw ValidationException::withMessages([
            'token' => ['Không thể xác nhận yêu cầu này. Hãy gửi yêu cầu mới từ Cài đặt tài khoản.'],
        ]);
    }
}
