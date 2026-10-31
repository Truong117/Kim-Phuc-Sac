<?php

namespace App\Services;

use App\Models\User;
use App\Rules\UniqueCaseInsensitiveEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AccountService
{
    public function __construct(private readonly AccountEmailTransaction $emailTransactions) {}

    public function updateName(User $user, string $name, ?string $expectedName = null): User
    {
        return DB::transaction(function () use ($user, $name, $expectedName): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($expectedName !== null && $locked->name !== $expectedName) {
                throw new ConflictHttpException('Hồ sơ đã thay đổi. Vui lòng tải lại trang trước khi lưu.');
            }
            $locked->update(['name' => $name]);

            return $locked->refresh();
        });
    }

    public function updatePassword(User $user, string $password, string $currentPassword): User
    {
        return DB::transaction(function () use ($user, $password, $currentPassword): User {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $this->confirmPassword($locked, $currentPassword);
            $locked->update(['password' => $password]);

            // Sanctum reads this guard-owned instance when storing the session password hash.
            return $user->refresh();
        });
    }

    public function updateEmail(User $actor, string $email, string $currentPassword, ?string $expectedEmail = null): User
    {
        $email = Str::lower(trim($email));
        $expectedEmail = $expectedEmail === null ? null : Str::lower(trim($expectedEmail));

        return $this->emailTransactions->run(function () use ($actor, $email, $currentPassword, $expectedEmail): User {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $this->confirmPassword($user, $currentPassword);

            if ($expectedEmail !== null && Str::lower(trim($user->email)) !== $expectedEmail) {
                throw new ConflictHttpException('Email đã thay đổi. Vui lòng tải lại trang trước khi lưu.');
            }

            Validator::make(['email' => $email], [
                'email' => ['required', 'string', 'email:rfc', 'max:255', new UniqueCaseInsensitiveEmail($user->id)],
            ])->validate();

            if (Str::lower(trim($user->email)) === $email) {
                return $user;
            }

            $previousEmail = $user->email;
            $user->email = $email;
            $user->email_verified_at = null;
            $user->save();
            // The mandatory audit record commits or rolls back with the email.
            // It is independent of application log levels and file logger availability.
            DB::table('account_audit_events')->insert([
                'event_type' => 'account.email_changed',
                'actor_user_id' => $user->id,
                'subject_user_id' => $user->id,
                'previous_email' => $previousEmail,
                'email' => $email,
                'created_at' => now(),
            ]);

            return $user->refresh();
        });
    }

    private function confirmPassword(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['Mật khẩu hiện tại không chính xác.']]);
        }
    }
}
