<?php

namespace App\Http\Requests\Integrations;

use App\Services\Integrations\IntegrationUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware enforces both view and manage permissions.
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach (['name', 'url', 'note'] as $field) {
            if (is_string($this->input($field))) {
                $clean[$field] = trim($this->input($field));
            }
        }
        $this->merge($clean);
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['drive', 'social'])],
            'platform' => ['nullable', 'required_if:kind,social', Rule::in(['tiktok', 'facebook', 'zalo', 'metaAds'])],
            'name' => ['required', 'string', 'max:150'],
            'url' => ['required', 'string', 'max:1000', 'url:https'],
            'responsible_user_id' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
            'enabled' => ['required', 'boolean'],
            'version' => [$this->isMethod('POST') ? 'prohibited' : 'required', 'integer', 'min:1'],
            'organization_id' => ['prohibited'],
            'url_hash' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
            'connection_status' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->hasAny(['kind', 'platform', 'url'])) {
                return;
            }
            if (IntegrationUrl::identity($this->string('url')->toString(), $this->string('kind')->toString(), $this->input('platform')) === null) {
                $validator->errors()->add('url', 'Đường dẫn không hợp lệ hoặc không thuộc nền tảng đã chọn.');
            }
        }];
    }
}
