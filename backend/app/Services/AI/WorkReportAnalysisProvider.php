<?php

namespace App\Services\AI;

use App\Models\WorkReportAnalysis;
use App\Models\WorkReportAnalysisUnit;

class WorkReportAnalysisProvider
{
    public function analyze(WorkReportAnalysis $run, WorkReportAnalysisUnit $unit, array $input): array
    {
        $settings = $run->analysis_config;
        WorkReportAnalysisOutput::assertExecutable($settings);
        if ($settings['driver'] === 'fake') {
            return ['result' => WorkReportAnalysisOutput::fake($input), 'usage_record_id' => null,
                'input_tokens' => null, 'output_tokens' => null, 'cost_microusd' => null];
        }
        if ($settings['driver'] !== 'openai') {
            throw new AiGatewayException('AI_NOT_CONFIGURED', 'Unsupported provider.');
        }
        // Secret stays in process configuration, never in snapshots or serialized jobs.
        $tokens = WorkReportExecutionPolicy::outputTokens($settings, str_starts_with($unit->unit_key, 'summary'));
        $gateway = new OpenAiGateway([...$settings['gateway'], 'max_output_tokens' => $tokens,
            'api_key' => config('work_report_ai.gateway.api_key')]);
        $response = $gateway->respond(new AiGatewayRequest(
            organizationId: (int) $run->organization_id,
            requestedByUserId: (int) $run->requested_by_user_id,
            feature: 'work_report_analysis', idempotencyKey: 'work-analysis:'.$run->id.':'.$unit->unit_key,
            instructions: WorkReportAnalysisOutput::instructions(), input: $input,
            schemaName: 'kps_work_report_analysis_v2', schema: WorkReportAnalysisOutput::schema(),
            maxOutputTokens: $tokens,
        ));

        return ['result' => $response->result, 'usage_record_id' => $response->usageRecordId,
            'input_tokens' => $response->inputTokens, 'output_tokens' => $response->outputTokens,
            // A conservative budget reservation is not a measured bill.
            'cost_microusd' => $response->inputTokens === null || $response->outputTokens === null ? null : $response->estimatedCostMicrousd];
    }
}
