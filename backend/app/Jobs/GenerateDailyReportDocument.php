<?php

namespace App\Jobs;

use App\Models\DailyReportAiDocument;
use App\Models\DailyReportAiUnit;
use App\Models\User;
use App\Services\AI\AiGatewayException;
use App\Services\AI\Daily\DailyDocumentProvider;
use App\Services\AI\Daily\DailyDocumentService;
use App\Services\AI\Daily\DailyReportOutput;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Throwable;

class GenerateDailyReportDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 270;

    public function __construct(public readonly int $unitId) {}

    public function handle(DailyDocumentService $service, DailyDocumentProvider $provider): void
    {
        $unit = DailyReportAiUnit::query()->findOrFail($this->unitId);
        $document = DailyReportAiDocument::query()->findOrFail($unit->daily_report_ai_document_id);
        $token = (string) Str::uuid();
        $claimed = DailyReportAiUnit::query()->whereKey($unit->id)->where('status', 'pending')
            ->where('attempts', '<', $document->analysis_config['max_attempts'])
            ->update(['status' => 'running', 'attempts' => $unit->attempts + 1, 'lease_token' => $token, 'leased_until' => now()->addSeconds(420)]);
        if (! $claimed) {
            return;
        }
        try {
            $service->authorized(User::query()->findOrFail($document->requested_by_user_id), $document->id);
            $result = DailyReportOutput::validate($provider->analyze($document, $unit), $unit->input);
            DailyReportAiUnit::query()->whereKey($unit->id)->where('status', 'running')->where('lease_token', $token)
                ->where('leased_until', '>', now())->update(['status' => 'completed', 'result' => app('encrypter')->encrypt(json_encode($result, JSON_THROW_ON_ERROR), false),
                    'error_code' => null, 'lease_token' => null, 'leased_until' => null]);
        } catch (Throwable $e) {
            DailyReportAiUnit::query()->whereKey($unit->id)->where('status', 'running')->where('lease_token', $token)
                ->update(['status' => 'failed', 'error_code' => $e instanceof AiGatewayException ? $e->errorCode : (in_array($e->getMessage(), ['AI_INVALID_EVIDENCE', 'AI_INVALID_SCHEMA', 'AI_INCOMPLETE_INVENTORY'], true) ? $e->getMessage() : 'AI_DAILY_PROCESSING_FAILED'), 'lease_token' => null, 'leased_until' => null]);
        }
        DailyDocumentService::settle($document);
    }
}
