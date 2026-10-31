<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmEmailChangeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'current_password' => ['required', 'string'],
            'user_id' => ['prohibited'],
            'email' => ['prohibited'],
        ];
    }
}
