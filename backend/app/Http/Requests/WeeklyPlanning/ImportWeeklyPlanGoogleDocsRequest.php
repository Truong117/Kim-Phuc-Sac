<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsDocumentReference;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class ImportWeeklyPlanGoogleDocsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'document_url' => [
                'bail',
                'required',
                'string',
                'max:2048',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        GoogleDocsDocumentReference::documentIdFromUrl((string) $value);
                    } catch (Throwable) {
                        $fail('URL Google Docs không hợp lệ.');
                    }
                },
            ],
            'expected_snapshot_sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'request_id' => ['required', 'uuid'],
            'organization_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'document_id' => ['prohibited'],
            'passages' => ['prohibited'],
            'assignee_id' => ['prohibited'],
        ];
    }

    public function documentUrl(): string
    {
        return (string) $this->validated('document_url');
    }
}
