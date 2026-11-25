<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DeleteReportHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware and the service enforce report_holidays.manage.
    }

    public function rules(): array
    {
        return ['expected_version' => ['required', 'integer', 'min:1']];
    }
}
