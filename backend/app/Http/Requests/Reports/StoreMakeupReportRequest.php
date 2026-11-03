<?php

namespace App\Http\Requests\Reports;

use Illuminate\Validation\Rule;

class StoreMakeupReportRequest extends ReportItemsRequest
{
    public function rules(): array
    {
        return array_replace(parent::rules(), [
            'report_date' => ['required', 'date_format:Y-m-d'],
            'form_version' => ['required', 'integer', Rule::in([3])],
        ]);
    }
}
