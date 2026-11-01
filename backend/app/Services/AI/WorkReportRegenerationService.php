<?php

namespace App\Services\AI;

use App\Models\User;
use App\Models\WorkReportAnalysis;
use App\Models\WorkReportAnalysisRevision;
use App\Models\WorkReportRegenerationRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class WorkReportRegenerationService
{
    public function __construct(private readonly WorkReportAnalysisService $analyses, private readonly WorkReportSnapshot $sources) {}

    public function create(User $viewer, int $sourceId, string $key, ?string $feedback): array
    {
        // Validate even for non-HTTP callers. Hash raw feedback only for replay conflicts; never persist its text.
        if (! Str::isUuid($key) || mb_strlen($feedback ?? '') > 1000) {
            throw ValidationException::withMessages(['review_feedback' => 'Mã yêu cầu không hợp lệ hoặc góp ý vượt 1.000 ký tự.']);
        }
        $key = strtolower($key);
        $source = $this->analyses->show($viewer, $sourceId);
        $requestHash = WorkReportSnapshot::hash([$sourceId, $feedback ?? '']);

        $operation = DB::transaction(function () use ($viewer, $source, $key, $feedback, $requestHash) {
            // Serialize the short allocation transaction across all requesters in this organization.
            // Providers run after commit. The unique operation key also protects replay across processes.
            DB::table('organizations')->where('id', $source->organization_id)->lockForUpdate()->firstOrFail();
            $existing = WorkReportRegenerationRequest::query()->where('organization_id', $source->organization_id)
                ->where('requested_by_user_id', $viewer->id)->where('idempotency_key', $key)->first();
            if ($existing !== null) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new ConflictHttpException('Mã xác nhận đã được dùng cho yêu cầu khác. Hãy kiểm tra lượt đã tạo.');
                }
                $this->analyses->show($viewer, (int) $existing->result_analysis_id);

                return $existing;
            }
            $source = $this->analyses->show($viewer, (int) $source->id);
            if (! config('work_report_ai.enabled') || ! in_array($source->status, ['completed', 'partial'], true)) {
                throw ValidationException::withMessages(['analysis' => 'Chỉ tạo bản mới khi tính năng đang bật và bản nguồn đã hoàn tất hoặc hoàn tất một phần.']);
            }
            $settings = $this->analyses->settings();
            $feedback = $feedback === null || trim($feedback) === '' ? null : $this->sources->redactFeedback($viewer, trim($feedback), $source->snapshot);
            $inputs = $this->analyses->inputsFor($source->snapshot, $settings, $feedback);
            $fingerprint = WorkReportSnapshot::hash([$source->scope_hash, $source->source_hash, $source->snapshot['period'], $source->department_id, $settings, $feedback]);
            $run = WorkReportAnalysis::query()->where('organization_id', $source->organization_id)
                ->whereIn('status', ['pending', 'running'])
                ->whereHas('revisionRecord', fn ($q) => $q->where('fingerprint', $fingerprint))->lockForUpdate()->first();
            $reused = $run !== null;
            if ($run !== null) {
                $this->analyses->show($viewer, (int) $run->id);
            } else {
                $run = WorkReportAnalysis::query()->create([
                    'organization_id' => $source->organization_id, 'requested_by_user_id' => $viewer->id,
                    // Distinct run identity, never a fabricated source hash or changed canonical cache contract.
                    'cache_key' => WorkReportSnapshot::hash(['regeneration', $source->organization_id, $viewer->id, $key]),
                    'scope_hash' => $source->scope_hash, 'source_hash' => $source->source_hash,
                    'start_date' => $source->start_date->toDateString(), 'end_date' => $source->end_date->toDateString(), 'days' => $source->days,
                    'department_id' => $source->department_id, 'snapshot' => $source->snapshot, 'analysis_config' => $settings,
                    'captured_at' => $source->captured_at, 'status' => 'pending',
                ]);
                $root = $source->revisionRecord?->root_analysis_id ?? $source->id;
                $number = 1 + (WorkReportAnalysisRevision::query()->where('root_analysis_id', $root)->max('revision_number') ?? 1);
                WorkReportAnalysisRevision::query()->create([
                    'analysis_id' => $run->id, 'organization_id' => $run->organization_id,
                    'parent_analysis_id' => $source->id, 'root_analysis_id' => $root, 'revision_number' => $number,
                    'fingerprint' => $fingerprint, 'review_feedback' => $feedback,
                ]);
                $this->analyses->initializeUnits($run, $inputs);
            }

            return WorkReportRegenerationRequest::query()->create([
                'organization_id' => $source->organization_id, 'requested_by_user_id' => $viewer->id,
                'source_analysis_id' => $source->id, 'result_analysis_id' => $run->id,
                'idempotency_key' => $key, 'request_hash' => $requestHash, 'reused_existing' => $reused,
            ]);
        });

        return $this->payload($viewer, $operation);
    }

    public function lookup(User $viewer, string $key): array
    {
        $membership = $this->sources->membership($viewer);
        $operation = WorkReportRegenerationRequest::query()->where('organization_id', $membership->organization_id)
            ->where('requested_by_user_id', $viewer->id)->where('idempotency_key', strtolower($key))->firstOrFail();
        $this->analyses->show($viewer, (int) $operation->source_analysis_id);

        return $this->payload($viewer, $operation);
    }

    private function payload(User $viewer, WorkReportRegenerationRequest $operation): array
    {
        return ['data' => $this->analyses->payload($viewer, $this->analyses->show($viewer, (int) $operation->result_analysis_id)),
            'operation' => ['key' => $operation->idempotency_key, 'source_analysis_id' => $operation->source_analysis_id,
                'reused_existing' => $operation->reused_existing]];
    }
}
