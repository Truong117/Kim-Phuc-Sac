<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use Illuminate\Http\JsonResponse;
use RuntimeException;
use Throwable;

final class GoogleDocsSourceException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->httpStatus);
    }
}
