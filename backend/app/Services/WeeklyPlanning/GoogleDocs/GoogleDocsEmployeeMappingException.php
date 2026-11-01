<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use Illuminate\Http\JsonResponse;
use RuntimeException;

final class GoogleDocsEmployeeMappingException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->httpStatus);
    }
}
