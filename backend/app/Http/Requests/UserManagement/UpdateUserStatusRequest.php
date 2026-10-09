<?php

namespace App\Http\Requests\UserManagement;

class UpdateUserStatusRequest extends UserManagementRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'is_active' => ['required', 'boolean'],
        ];
    }
}
