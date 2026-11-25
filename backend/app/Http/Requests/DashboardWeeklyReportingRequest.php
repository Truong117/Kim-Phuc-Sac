<?php

namespace App\Http\Requests;

use App\Services\ReportClock;
use Illuminate\Foundation\Http\FormRequest;

class DashboardWeeklyReportingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(ReportClock $clock): array
    {
        return [
            'week_start' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$clock->businessDate()],
        ];
    }
}
