<?php

namespace App\Http\Requests\WeeklyPlanning;

use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsDocumentReference;
use Illuminate\Foundation\Http\FormRequest;
use Throwable;

class ApproveGoogleDocsFolderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'google_folder_id' => [
                'bail',
                'required',
                'string',
                'max:200',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    try {
                        GoogleDocsDocumentReference::validateDocumentId((string) $value);
                    } catch (Throwable) {
                        $fail('Google Drive folder ID không hợp lệ.');
                    }
                },
            ],
            'organization_id' => ['prohibited'],
        ];
    }

    public function folderId(): string
    {
        return (string) $this->validated('google_folder_id');
    }
}
