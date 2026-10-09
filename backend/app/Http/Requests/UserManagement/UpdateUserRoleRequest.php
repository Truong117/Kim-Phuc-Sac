<?php

namespace App\Http\Requests\UserManagement;

use Illuminate\Validation\Rule;

class UpdateUserRoleRequest extends UserManagementRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
        ];
    }
}
