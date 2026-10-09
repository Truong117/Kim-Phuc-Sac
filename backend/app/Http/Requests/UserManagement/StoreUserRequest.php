<?php

namespace App\Http\Requests\UserManagement;

use App\Rules\UniqueCaseInsensitiveEmail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreUserRequest extends UserManagementRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimInput(['name', 'email']);

        if (is_string($this->input('email'))) {
            $this->merge(['email' => Str::lower($this->input('email'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', new UniqueCaseInsensitiveEmail],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            'department_id' => ['nullable', 'integer', $this->activeKpsReference('departments')],
            'location_id' => ['nullable', 'integer', $this->activeKpsReference('locations')],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
        ];
    }
}
