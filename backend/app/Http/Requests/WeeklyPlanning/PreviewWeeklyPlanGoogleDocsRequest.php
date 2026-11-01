<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsDocumentReference;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class PreviewWeeklyPlanGoogleDocsRequest extends FormRequest
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
            'organization_id' => ['prohibited'],
            'document_id' => ['prohibited'],
        ];
    }

    public function documentUrl(): string
    {
        return (string) $this->validated('document_url');
    }
}
