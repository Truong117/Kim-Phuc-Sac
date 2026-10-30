<?php

namespace App\Services\WeeklyPlanning;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class WeeklyPlanV3BatchException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'code' => $this->errorCode,
            'message' => $this->getMessage(),
        ], $this->status);
    }
}
