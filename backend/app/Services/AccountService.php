<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AccountService
{
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
            if (! Hash::check($currentPassword, $locked->password)) {
                throw ValidationException::withMessages(['current_password' => ['Mật khẩu hiện tại không chính xác.']]);
            }
            $locked->update(['password' => $password]);
            DB::table('account_email_changes')->where('user_id', $user->id)->delete();

            return $locked->refresh();
        });
    }
}
