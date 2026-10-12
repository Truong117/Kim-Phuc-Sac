<?php

namespace App\Services;

use App\Models\User;

class AccountService
{
    public function updateName(User $user, string $name): User
    {
        $user->update(['name' => $name]);

        return $user->refresh();
    }

    public function updatePassword(User $user, string $password): User
    {
        $user->update(['password' => $password]);

        return $user->refresh();
    }
}
