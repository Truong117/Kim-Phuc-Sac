<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class UpdateEmailRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['email', 'expected_email'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => Str::lower(trim($this->input($field)))]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'current_password' => ['required', 'string'],
            'expected_email' => ['sometimes', 'required', 'string', 'email:rfc', 'max:255'],
            'user_id' => ['prohibited'],
            'id' => ['prohibited'],
            'name' => ['prohibited'],
            'role' => ['prohibited'],
            'role_id' => ['prohibited'],
            'permissions' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'department_id' => ['prohibited'],
            'location_id' => ['prohibited'],
            'membership_id' => ['prohibited'],
            'is_active' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
        ];
    }
}
