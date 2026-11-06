<?php

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Social\ReportFacebookPosts;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ReportFacebookPostsController extends Controller
{
    public function __invoke(Request $request, ReportFacebookPosts $posts): JsonResponse
    {
        $input = $request->validate([
            'entry_id' => ['sometimes', 'integer', 'min:1'],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:from_date'],
            'cursor' => ['sometimes', 'string', 'max:16384'],
            'source_url' => ['sometimes', 'string', 'max:2048'],
        ]);
        if (CarbonImmutable::parse($input['from_date'])->diffInDays(CarbonImmutable::parse($input['to_date'])) > 366) {
            throw ValidationException::withMessages(['to_date' => 'Chọn khoảng ngày tối đa một năm.']);
        }
        try {
            return response()->json($posts->listing($request->user(), $input))->header('Cache-Control', 'no-store');
        } catch (RuntimeException $exception) {
            $code = in_array($exception->getMessage(), ['SOCIAL_NOT_CONFIGURED', 'SOCIAL_CONNECTION_UNAVAILABLE', 'SOCIAL_RECONNECT_REQUIRED', 'SOCIAL_RATE_LIMITED', 'SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE', 'SOCIAL_PROVIDER_UNAVAILABLE'], true) ? $exception->getMessage() : 'SOCIAL_PROVIDER_UNAVAILABLE';

            return response()->json(['message' => 'Chưa tải được danh sách bài Facebook. Hãy thử lại hoặc báo người quản lý kiểm tra kết nối.', 'code' => $code], $code === 'SOCIAL_RATE_LIMITED' ? 429 : 422)->header('Cache-Control', 'no-store');
        }
    }
}
