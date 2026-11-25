<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveReportHolidayRequest;
use App\Services\ReportHolidayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportHolidayController extends Controller
{
    public function index(Request $request, ReportHolidayService $holidays): JsonResponse
    {
        return response()->json($holidays->listing($request->user()));
    }

    public function store(SaveReportHolidayRequest $request, ReportHolidayService $holidays): JsonResponse
    {
        return response()->json(['data' => $holidays->serialize($holidays->save($request->user(), null, $request->validated()))], 201);
    }

    public function update(SaveReportHolidayRequest $request, ReportHolidayService $holidays, int $id): JsonResponse
    {
        return response()->json(['data' => $holidays->serialize($holidays->save($request->user(), $id, $request->validated()))]);
    }
}
