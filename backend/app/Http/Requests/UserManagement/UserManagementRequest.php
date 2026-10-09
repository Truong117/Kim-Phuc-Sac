<?php

namespace App\Http\Requests\UserManagement;

use App\Http\Middleware\EnsureActiveKpsMembership;
use App\Models\OrganizationMembership;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

abstract class UserManagementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function activeKpsReference(string $table): Exists
    {
        $membership = $this->attributes->get(EnsureActiveKpsMembership::REQUEST_ATTRIBUTE);
        $organizationId = $membership instanceof OrganizationMembership
            ? $membership->organization_id
            : 0;

        return Rule::exists($table, 'id')->where(
            fn (Builder $query): Builder => $query
                ->where('organization_id', $organizationId)
                ->where('is_active', true),
        );
    }

    /**
     * @param  list<string>  $keys
     */
    protected function trimInput(array $keys): void
    {
        $normalized = [];

        foreach ($keys as $key) {
            $value = $this->input($key);

            if (is_string($value)) {
                $normalized[$key] = trim($value);
            }
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }
}
