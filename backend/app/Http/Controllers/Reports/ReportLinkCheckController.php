<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Social\ReportLinkCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportLinkCheckController extends Controller
{
    public function __invoke(Request $request, ReportLinkCheck $checker): JsonResponse
    {
        $input = $request->validate([
            'urls' => ['required', 'array', 'min:1', 'max:10'],
            'urls.*' => ['required', 'string', 'max:2048', 'url:http,https', 'regex:~^https?://[^\s@]+$~iD'],
        ]);

        return response()->json(['data' => $checker->check($request->user(), $input['urls'])])->header('Cache-Control', 'no-store');
    }
}
