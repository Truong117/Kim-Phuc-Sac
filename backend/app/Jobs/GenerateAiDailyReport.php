<?php

namespace App\Jobs;

use App\Models\AiDailyReportRun;
use App\Models\User;
use App\Services\AI\AiDailyReportService;
use App\Services\AI\DailyReportAiProvider;
use App\Services\AI\DailyReportContextBuilder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

class GenerateAiDailyReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 100;

    public function __construct(public readonly int $runId) {}

    public function handle(
        AiDailyReportService $reports,
        DailyReportContextBuilder $contextBuilder,
        DailyReportAiProvider $provider,
    ): void {
        $run = AiDailyReportRun::query()->findOrFail($this->runId);
        if ($run->status === 'completed') {
            return;
        }
        $viewer = User::query()->findOrFail($run->requested_by_user_id);
        // Access must still exist when the queued job actually executes.
        $reports->ensurePermission($viewer);
        $run->update(['status' => 'running', 'error_code' => null]);
        $context = $contextBuilder->build($viewer, $run->report_date->toDateString());
        $analysis = $provider->analyze($context, [
            'organization_id' => (int) $run->organization_id,
            'requested_by_user_id' => (int) $run->requested_by_user_id,
            'idempotency_key' => 'daily-report-run:'.$run->id,
        ]);
        $this->validateAnalysis($analysis['result'], $context['source_report_ids']);
        $run->update([
            'source_report_ids' => $context['source_report_ids'],
            'work_counts' => $context['work_counts'],
            'result' => $analysis['result'],
            'provider' => $analysis['provider'],
            'model' => $analysis['model'],
            'input_tokens' => $analysis['input_tokens'],
            'output_tokens' => $analysis['output_tokens'],
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /** @param list<int> $sourceIds */
    private function validateAnalysis(array $result, array $sourceIds): void
    {
        $resultFields = ['overview', 'highlights', 'attention', 'recommendations'];
        if (array_diff(array_keys($result), $resultFields) !== []
            || array_diff($resultFields, array_keys($result)) !== []) {
            throw new RuntimeException('AI output schema invalid.');
        }
        if (! isset($result['overview']) || ! is_string($result['overview']) || strlen($result['overview']) > 3000) {
            throw new RuntimeException('AI output schema invalid.');
        }
        foreach (['highlights', 'attention', 'recommendations'] as $field) {
            if (! isset($result[$field]) || ! is_array($result[$field])
                || ! array_is_list($result[$field]) || count($result[$field]) > 12) {
                throw new RuntimeException('AI output schema invalid.');
            }
            foreach ($result[$field] as $item) {
                if (! is_array($item) || ! is_string($item['text'] ?? null)
                    || array_diff(array_keys($item), ['text', 'report_ids']) !== []
                    || array_diff(['text', 'report_ids'], array_keys($item)) !== []
                    || strlen($item['text']) > 1500 || ! is_array($item['report_ids'] ?? null)
                    || ! array_is_list($item['report_ids'])) {
                    throw new RuntimeException('AI output schema invalid.');
                }
                if ($item['report_ids'] === []) {
                    throw new RuntimeException('AI evidence missing source reference.');
                }
                foreach ($item['report_ids'] as $id) {
                    if (! is_int($id) || ! in_array($id, $sourceIds, true)) {
                        throw new RuntimeException('Unverifiable AI source reference.');
                    }
                }
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        // Do not persist the exception message, which might include provider data.
        AiDailyReportRun::query()->whereKey($this->runId)
            ->where('status', '!=', 'completed')
            ->update(['status' => 'failed', 'error_code' => 'AI_GENERATION_FAILED']);
    }
}
