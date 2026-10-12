<?php

namespace App\Http\Requests\Account;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['prohibited'],
            'organization' => ['prohibited'],
            'organization_id' => ['prohibited'],
            'department' => ['prohibited'],
            'department_id' => ['prohibited'],
            'location' => ['prohibited'],
            'location_id' => ['prohibited'],
            'role' => ['prohibited'],
            'role_id' => ['prohibited'],
            'membership_status' => ['prohibited'],
            'is_active' => ['prohibited'],
            'permissions' => ['prohibited'],
            'user_id' => ['prohibited'],
        ];
    }
}
