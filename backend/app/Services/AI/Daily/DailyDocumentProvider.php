<?php

namespace App\Services\AI\Daily;

use App\Models\DailyReportAiDocument;
use App\Models\DailyReportAiUnit;
use App\Models\User;
use App\Services\AI\AiGatewayException;
use App\Services\AI\AiGatewayRequest;
use App\Services\AI\OpenAiGateway;
use App\Services\AI\WorkReportSnapshot;
use Illuminate\Support\Facades\Log;
use Throwable;

class DailyDocumentProvider
{
    public function analyze(DailyReportAiDocument $document, DailyReportAiUnit $unit): array
    {
        $config = $document->analysis_config;
        if ($config['driver'] !== config('daily_report_ai.driver') || ($config['schema'] ?? null) !== DailyReportOutput::VERSION
            || ($config['instructions_hash'] ?? null) !== hash('sha256', DailyDocumentContract::instructions())
            || ($config['schema_hash'] ?? null) !== DailyReportSource::hash(DailyDocumentContract::schema())) {
            throw new AiGatewayException('AI_ANALYSIS_VERSION_UNAVAILABLE', 'Saved daily contract cannot be reproduced.');
        }
        if ($config['driver'] === 'fake') {
            return app(FakeDailyDocumentProvider::class)->analyze($unit->input);
        }
        if ($config['driver'] !== 'openai' || ($config['gateway']['model'] ?? null) !== 'gpt-6-luna') {
            throw new AiGatewayException('AI_NOT_CONFIGURED', 'Unsupported daily provider.');
        }
        $caps = array_filter([(float) config('kps_ai.monthly_budget_usd'), (float) config('work_report_ai.gateway.monthly_budget_usd')], fn ($cap) => $cap > 0);
        $monthly = $caps === [] ? 0 : min((float) $config['gateway']['monthly_budget_usd'], (float) config('daily_report_ai.gateway.monthly_budget_usd'), ...$caps);
        $gateway = new OpenAiGateway([...$config['gateway'], 'monthly_budget_usd' => $monthly, 'api_key' => config('daily_report_ai.gateway.api_key')]);
        $source = $unit->input;
        // Provider sees no user identity, submission metadata, URLs or credentials.
        $viewer = User::query()->findOrFail($document->requested_by_user_id);
        $input = app(WorkReportSnapshot::class)->dailyProviderInput($viewer, $source);
        $response = $gateway->respond(new AiGatewayRequest(
            organizationId: (int) $document->organization_id, requestedByUserId: (int) $document->requested_by_user_id,
            feature: 'daily_report_document', idempotencyKey: 'daily-document:'.$document->id.':'.$unit->part,
            instructions: DailyDocumentContract::instructions(), input: $input,
            schemaName: 'kps_daily_document_v4', schema: DailyDocumentContract::schema(),
            maxOutputTokens: (int) $config['gateway']['max_output_tokens'], reasoningEffort: 'low',
        ));
        $stage = 'bind_redacted';
        try {
            $redacted = DailyEvidence::bind($response->result, $input);
            $stage = 'validate_redacted';
            DailyReportOutput::validate($redacted, $input);
            $stage = 'bind_original';
            $original = DailyEvidence::bind($response->result, $source);
            $stage = 'validate_original';

            return DailyReportOutput::validate($original, $source);
        } catch (Throwable $e) {
            Log::warning('daily_document_validation_rejected', [
                'document_id' => $document->id, 'unit_id' => $unit->id,
                'usage_record_id' => $response->usageRecordId, 'stage' => $stage,
            ]);
            $gateway->rejectCompletedResult($response->usageRecordId, 'AI_INVALID_EVIDENCE');
            throw $e;
        }
    }
}

