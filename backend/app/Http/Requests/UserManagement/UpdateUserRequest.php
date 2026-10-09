<?php

namespace App\Http\Requests\UserManagement;

use App\Rules\UniqueCaseInsensitiveEmail;
use Illuminate\Support\Str;

class UpdateUserRequest extends UserManagementRequest
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
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                new UniqueCaseInsensitiveEmail((int) $this->route('id')),
            ],
            'department_id' => [
                'sometimes',
                'nullable',
                'integer',
                $this->activeKpsReference('departments'),
            ],
            'location_id' => [
                'sometimes',
                'nullable',
                'integer',
                $this->activeKpsReference('locations'),
            ],
        ];
    }
}
