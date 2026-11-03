<?php

namespace App\Services\AI\Daily;

use App\Jobs\GenerateDailyReportDocument;
use App\Models\DailyReportAiDocument;
use App\Models\DailyReportAiUnit;
use App\Models\User;
use App\Services\AI\OpenAiGateway;
use App\Services\AI\WorkReportSnapshot;
use App\Services\AuthorizationService;
use App\Services\ReportClock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyDocumentService
{
    public function __construct(
        private readonly AuthorizationService $authorization,
        private readonly ReportClock $clock,
    ) {}

    private function eligible(User $viewer): Builder
    {
        abort_unless($this->authorization->allows($viewer, 'ai.insights.view') && $this->authorization->allows($viewer, 'reports.view'), 403);

        return app(WorkReportSnapshot::class)->scopedReports($viewer)->where('locked_at', '<=', $this->clock->now()->utc());
    }

    private function enabled(): void
    {
        abort_unless(config('daily_report_ai.enabled'), 409, 'Daily document feature is disabled.');
    }

    public function listing(User $viewer, string $date, int $page): array
    {
        $query = $this->eligible($viewer)->whereDate('report_date', $date);
        if (! config('daily_report_ai.enabled')) {
            return ['enabled' => false, 'date' => $date, 'reports' => [], 'total' => 0, 'page' => $page, 'last_page' => 1];
        }
        $reports = $query->with(['items', 'user:id,name'])->orderBy('id')->paginate(25, ['*'], 'page', $page);
        $documents = DailyReportAiDocument::query()->with('units')->whereIn('daily_report_id', $reports->pluck('id'))
            ->orderByDesc('id')->get()->unique('daily_report_id')->keyBy('daily_report_id');

        return ['enabled' => true, 'date' => $date, 'total' => $reports->total(), 'page' => $reports->currentPage(), 'last_page' => $reports->lastPage(),
            'reports' => $reports->map(function ($report) use ($documents) {
                $snapshot = DailyReportSource::capture($report);
                $document = $documents->get($report->id);

                return ['report_id' => $report->id, 'employee_name' => $report->user?->name ?? '',
                    'source' => $snapshot, 'document' => $document ? $this->payload($document) : null,
                    'source_changed' => $document && $document->source_hash !== DailyReportSource::hash($snapshot)];
            })->all()];
    }

    public function request(User $viewer, int $reportId): array
    {
        $this->enabled();
        $settings = ['driver' => config('daily_report_ai.driver'), 'model' => config('daily_report_ai.model'),
            'schema' => DailyReportOutput::VERSION, 'source' => DailyReportSource::VERSION, 'prompt' => 'daily-document-v3',
            'unit_bytes' => (int) config('daily_report_ai.unit_bytes'), 'max_parts' => (int) config('daily_report_ai.max_parts'),
            'max_attempts' => min(2, max(1, (int) config('daily_report_ai.max_attempts'))),
            'instructions_hash' => hash('sha256', DailyDocumentContract::instructions()),
            'schema_hash' => DailyReportSource::hash(DailyDocumentContract::schema()),
            'max_document_budget_usd' => (float) config('daily_report_ai.max_document_budget_usd')];
        $gateway = config('daily_report_ai.gateway');
        unset($gateway['api_key']);
        $existingCaps = array_filter([(float) config('kps_ai.monthly_budget_usd'), (float) config('work_report_ai.gateway.monthly_budget_usd')], fn ($cap) => $cap > 0);
        $gateway['monthly_budget_usd'] = $existingCaps === [] ? 0 : min($gateway['monthly_budget_usd'], ...$existingCaps);
        $settings['gateway'] = $gateway;
        if (! in_array($settings['driver'], ['fake', 'openai'], true)) {
            throw ValidationException::withMessages(['driver' => 'Driver AI ngày không được hỗ trợ.']);
        }
        if ($settings['driver'] === 'openai' && ! (new OpenAiGateway([...$gateway, 'api_key' => config('daily_report_ai.gateway.api_key')]))->isConfigured()) {
            throw ValidationException::withMessages(['driver' => 'Luna chưa đủ cấu hình khóa API, giá token và hạn mức.']);
        }
        $document = DB::transaction(function () use ($viewer, $reportId, $settings) {
            $report = $this->eligible($viewer)->with('items')->lockForUpdate()->findOrFail($reportId);
            $snapshot = DailyReportSource::capture($report);
            $parts = DailyReportSource::parts($snapshot, $settings['unit_bytes'], $settings['max_parts']);
            if ($settings['driver'] === 'openai') {
                $maximum = count($parts) * $settings['max_attempts'] * ($settings['gateway']['max_input_tokens'] * $settings['gateway']['input_usd_per_million_tokens']
                    + $settings['gateway']['max_output_tokens'] * $settings['gateway']['output_usd_per_million_tokens']);
                if ($settings['max_document_budget_usd'] <= 0 || $maximum > (int) round($settings['max_document_budget_usd'] * 1000000)) {
                    throw ValidationException::withMessages(['budget' => 'Báo cáo vượt dự phòng ngân sách ngày, gồm cả các lần thử lại. Chưa tạo lượt AI.']);
                }
            }
            $sourceHash = DailyReportSource::hash($snapshot);
            $key = DailyReportSource::hash([$report->organization_id, $reportId, $sourceHash, $settings]);
            $document = DailyReportAiDocument::query()->createOrFirst(['cache_key' => $key], [
                'organization_id' => $report->organization_id, 'daily_report_id' => $reportId,
                'requested_by_user_id' => $viewer->id, 'report_date' => $report->report_date,
                'source_hash' => $sourceHash, 'snapshot' => $snapshot, 'analysis_config' => $settings,
                'status' => 'pending', 'captured_at' => now(),
            ]);
            if ($document->wasRecentlyCreated) {
                foreach ($parts as $part => $input) {
                    $unit = $document->units()->create(['part' => $part, 'input' => $input, 'status' => 'pending']);
                    GenerateDailyReportDocument::dispatch($unit->id)->onConnection(config('daily_report_ai.queue_connection'))->onQueue('ai-daily-reports')->afterCommit();
                }
            }

            return $document;
        });

        return $this->payload($document->fresh());
    }

    public function authorized(User $viewer, int $documentId): DailyReportAiDocument
    {
        $this->enabled();
        // Query the current source permission before exposing any historical document payload.
        $allowed = $this->eligible($viewer)->select('daily_reports.id');

        return DailyReportAiDocument::query()->whereIn('daily_report_id', $allowed)->findOrFail($documentId);
    }

    public function show(User $viewer, int $id): array
    {
        return $this->payload($this->authorized($viewer, $id));
    }

    public function retry(User $viewer, int $id): array
    {
        DB::transaction(function () use ($viewer, $id): void {
            $document = $this->authorized($viewer, $id);
            $document = DailyReportAiDocument::query()->lockForUpdate()->findOrFail($document->id);
            abort_unless($document->analysis_config['driver'] === config('daily_report_ai.driver') && ($document->analysis_config['schema'] ?? null) === DailyReportOutput::VERSION, 409);
            $document->update(['requested_by_user_id' => $viewer->id]);
            foreach ($document->units()->where('status', 'failed')->get() as $unit) {
                if ($unit->attempts >= $document->analysis_config['max_attempts']) {
                    continue;
                }
                if (DailyReportAiUnit::query()->whereKey($unit->id)->where('status', 'failed')->update(['status' => 'pending', 'error_code' => null]) === 1) {
                    GenerateDailyReportDocument::dispatch($unit->id)->onConnection(config('daily_report_ai.queue_connection'))->onQueue('ai-daily-reports')->afterCommit();
                }
            }
            self::settle($document);
        });

        return $this->show($viewer, $id);
    }

    private function payload(DailyReportAiDocument $document): array
    {
        // All callers obtained sources through eligible(), including the request cache-hit path.
        $document->loadMissing('units');
        $units = $document->units;
        $current = ($document->analysis_config['schema'] ?? null) === DailyReportOutput::VERSION
            && ($document->analysis_config['driver'] ?? null) === config('daily_report_ai.driver')
            && ($document->analysis_config['instructions_hash'] ?? null) === hash('sha256', DailyDocumentContract::instructions())
            && ($document->analysis_config['schema_hash'] ?? null) === DailyReportSource::hash(DailyDocumentContract::schema());

        return ['id' => $document->id, 'report_id' => $document->daily_report_id,
            'status' => $document->status, 'requires_new_document' => ! $current, 'source' => $document->snapshot, 'source_hash' => $document->source_hash,
            'mode' => $document->analysis_config['driver'], 'model' => $document->analysis_config['driver'] === 'fake' ? null : $document->analysis_config['model'],
            'captured_at' => $document->captured_at->toIso8601String(), 'result' => $document->result,
            'completed_units' => $units->where('status', 'completed')->count(), 'total_units' => $units->count(),
            'units' => $units->map(fn ($u) => ['id' => $u->id, 'part' => $u->part, 'status' => $u->status,
                'attempts' => $u->attempts, 'error_code' => $u->error_code,
                'can_retry' => $current && $u->status === 'failed' && $u->attempts < $document->analysis_config['max_attempts']])->all()];
    }

    public static function settle(DailyReportAiDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $document = DailyReportAiDocument::query()->lockForUpdate()->findOrFail($document->id);
            $units = $document->units()->get();
            $completed = $units->where('status', 'completed');
            $active = $units->whereIn('status', ['pending', 'running'])->isNotEmpty();
            $status = $active ? 'running' : ($completed->count() === $units->count() ? 'completed' : ($completed->isNotEmpty() ? 'partial' : 'failed'));
            $document->update(['status' => $status,
                'result' => $completed->isEmpty() ? null : DailyReportOutput::assemble($completed->pluck('result')->all(), $document->snapshot),
                'completed_at' => $active ? null : now()]);
        });
    }

    public function recover(): int
    {
        $recovered = 0;
        $stalePending = now()->subSeconds(480);
        $candidates = DailyReportAiUnit::query()->where(function ($query) use ($stalePending): void {
            $query->where(fn ($q) => $q->where('status', 'running')->where('leased_until', '<=', now()))
                ->orWhere(fn ($q) => $q->where('status', 'pending')->where('updated_at', '<=', $stalePending));
        })->get();
        foreach ($candidates as $unit) {
            $query = DailyReportAiUnit::query()->whereKey($unit->id)->where('status', $unit->status);
            if ($unit->status === 'running') {
                $query->where('lease_token', $unit->lease_token)->where('leased_until', '<=', now());
            } else {
                $query->where('updated_at', '<=', $stalePending);
            }
            $changed = $query->update(['status' => 'failed',
                'error_code' => $unit->status === 'running' ? 'AI_WORKER_INTERRUPTED' : 'AI_DISPATCH_INTERRUPTED',
                'lease_token' => null, 'leased_until' => null]);
            if ($changed) {
                $recovered++;
                self::settle(DailyReportAiDocument::query()->findOrFail($unit->daily_report_ai_document_id));
            }
        }

        return $recovered;
    }
}
