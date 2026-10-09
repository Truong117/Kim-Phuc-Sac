<?php

namespace App\Http\Requests\UserManagement;

use Illuminate\Validation\Rule;

class IndexUsersRequest extends UserManagementRequest
{
    protected function prepareForValidation(): void
    {
        $this->trimInput(['search']);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'department_id' => ['nullable', 'integer', $this->activeKpsReference('departments')],
            'role_id' => ['nullable', 'integer', Rule::exists('roles', 'id')],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
