<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use App\Enums\WeeklyPlanParseStatus;
use App\Enums\WeeklyPlanSourceType;
use App\Enums\WeeklyPlanStatus;
use App\Models\OrganizationGoogleDocsFolder;
use App\Models\User;
use App\Models\WeeklyPlan;
use App\Models\WeeklyPlanEvent;
use App\Models\WeeklyPlanGoogleDocsSource;
use App\Services\WeeklyPlanning\WeeklyPlanClock;
use App\Services\WeeklyPlanning\WeeklyPlanScopeService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class GoogleDocsWeeklyPlanSourceService
{
    private const GOOGLE_DOCUMENT_MIME_TYPE = 'application/vnd.google-apps.document';

    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly WeeklyPlanClock $clock,
        private readonly GoogleDocsPreviewService $previews,
        private readonly GoogleDocsSnapshotValidator $snapshots,
    ) {}

    public function confirm(
        User $actor,
        string $documentUrl,
        string $expectedSnapshotSha256,
        string $requestId,
    ): WeeklyPlan {
        $membership = $this->scope->freshAuthorizedMembership(
            $actor,
            'weekly_plans.manage_draft',
        );
        if ($membership === null) {
            throw new AccessDeniedHttpException;
        }

        $organizationId = (int) $membership->organization_id;
        $documentId = GoogleDocsDocumentReference::documentIdFromUrl($documentUrl);
        $payloadSha256 = hash('sha256', $documentId.'|'.$expectedSnapshotSha256);
        $existing = $this->existingRequest($organizationId, $requestId);
        if ($existing !== null) {
            return $this->resolveIdempotentPlan($existing, $payloadSha256);
        }

        $week = $this->clock->nextWeek();
        if ($this->planForWeekExists($organizationId, $week['start'])) {
            throw $this->planExists();
        }

        // External provider operations intentionally finish before the write transaction.
        $authorized = $this->previews->readAuthorized($actor, $documentUrl);
        $snapshot = $authorized['snapshot'];
        if (! hash_equals($expectedSnapshotSha256, (string) ($snapshot['snapshot_sha256'] ?? ''))) {
            throw new GoogleDocsSourceException(
                'GOOGLE_DOCS_SNAPSHOT_CHANGED',
                409,
                'Tài liệu đã thay đổi sau lần xem trước. Vui lòng đọc lại tài liệu trước khi xác nhận.',
            );
        }

        $validated = $this->snapshots->validate(
            $snapshot,
            (string) $authorized['authorization']['document_id'],
        );
        $capturedAt = CarbonImmutable::now('UTC');

        try {
            return DB::transaction(function () use (
                $actor,
                $authorized,
                $capturedAt,
                $documentId,
                $expectedSnapshotSha256,
                $organizationId,
                $payloadSha256,
                $requestId,
                $snapshot,
                $validated,
                $week,
            ): WeeklyPlan {
                // Google calls may take long enough for membership or role
                // grants to change. Re-read both immediately before any write.
                $this->assertFreshAuthorization($actor, $organizationId);

                $existing = WeeklyPlanGoogleDocsSource::query()
                    ->where('organization_id', $organizationId)
                    ->where('request_id', $requestId)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return $this->resolveIdempotentPlan($existing, $payloadSha256);
                }

                $folder = OrganizationGoogleDocsFolder::query()
                    ->where('organization_id', $organizationId)
                    ->where('google_folder_id', $authorized['authorization']['approved_folder_id'])
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->first();
                if ($folder === null) {
                    throw new GoogleDocsSourceException(
                        'GOOGLE_DOCS_AUTHORIZATION_CHANGED',
                        409,
                        'Quyền truy cập tài liệu đã thay đổi. Vui lòng đọc lại tài liệu.',
                    );
                }

                // The folder row serializes confirms for this organization.
                // Recheck the idempotency key after acquiring it so concurrent
                // retries return the first committed plan instead of a week conflict.
                $existing = WeeklyPlanGoogleDocsSource::query()
                    ->where('organization_id', $organizationId)
                    ->where('request_id', $requestId)
                    ->first();
                if ($existing !== null) {
                    return $this->resolveIdempotentPlan($existing, $payloadSha256);
                }

                if (WeeklyPlan::query()
                    ->where('organization_id', $organizationId)
                    ->whereDate('week_start', $week['start'])
                    ->lockForUpdate()
                    ->exists()) {
                    throw $this->planExists();
                }

                // Keep the final check adjacent to the first write. The
                // earlier check also protects a concurrent idempotent return.
                $this->assertFreshAuthorization($actor, $organizationId);

                $plan = WeeklyPlan::query()->create([
                    'organization_id' => $organizationId,
                    'created_by' => $actor->id,
                    'week_start' => $week['start'],
                    'week_end' => $week['end'],
                    'status' => WeeklyPlanStatus::DRAFT,
                    'source_type' => WeeklyPlanSourceType::GOOGLE_DOCS,
                    'source_disk' => null,
                    'source_path' => null,
                    'original_filename' => 'Google Docs',
                    'source_extension' => 'gdoc',
                    'source_mime' => self::GOOGLE_DOCUMENT_MIME_TYPE,
                    'source_size' => 0,
                    'source_sha256' => $expectedSnapshotSha256,
                    'parse_status' => WeeklyPlanParseStatus::PARSED,
                    'parse_metadata' => [
                        'origin' => WeeklyPlanSourceType::GOOGLE_DOCS->value,
                        'tab_count' => count($validated['tabs']),
                        'passage_count' => $validated['passage_count'],
                        'character_count' => $validated['character_count'],
                    ],
                    'uploaded_at' => $capturedAt,
                ]);

                $plan->googleDocsSource()->create([
                    'organization_id' => $organizationId,
                    'approved_folder_id' => $folder->id,
                    'confirmed_by_user_id' => $actor->id,
                    'request_id' => $requestId,
                    'request_payload_sha256' => $payloadSha256,
                    'google_document_id' => $documentId,
                    'document_id_sha256' => hash('sha256', $documentId),
                    'approved_folder_id_sha256' => hash('sha256', $folder->google_folder_id),
                    'document_title' => $snapshot['title'],
                    'revision_id' => $snapshot['revision_id'],
                    'snapshot_sha256' => $expectedSnapshotSha256,
                    'tab_manifest' => $validated['tabs'],
                    'passage_count' => $validated['passage_count'],
                    'character_count' => $validated['character_count'],
                    'captured_at' => $capturedAt,
                    'last_verified_at' => $capturedAt,
                    'stale_at' => null,
                ]);

                foreach ($validated['passages'] as $passage) {
                    $plan->sourcePassages()->create([
                        'source_sha256' => $expectedSnapshotSha256,
                        'source_key' => $passage['source_key'],
                        'sequence' => $passage['sequence'],
                        'kind' => $passage['kind'],
                        'text' => $passage['text'],
                        'metadata' => $passage['metadata'],
                        'content_sha256' => $passage['content_sha256'],
                    ]);
                }

                $this->record($plan, $actor, 'GOOGLE_DOCS_SOURCE_CONFIRMED', [
                    'source_sha256' => $expectedSnapshotSha256,
                    'tab_count' => count($validated['tabs']),
                    'passage_count' => $validated['passage_count'],
                    'character_count' => $validated['character_count'],
                    'identity_verified' => false,
                ], $capturedAt);
                $this->record($plan, $actor, 'SOURCE_TEXT_EXTRACTED', [
                    'source_sha256' => $expectedSnapshotSha256,
                    'passage_count' => $validated['passage_count'],
                    'status' => $validated['passage_count'] === 0 ? 'EMPTY' : 'EXTRACTED',
                ], $capturedAt);

                return $plan;
            });
        } catch (QueryException $exception) {
            $existing = $this->existingRequest($organizationId, $requestId);
            if ($existing !== null) {
                return $this->resolveIdempotentPlan($existing, $payloadSha256);
            }
            if ($this->planForWeekExists($organizationId, $week['start'])) {
                throw $this->planExists($exception);
            }

            throw $exception;
        }
    }

    private function existingRequest(int $organizationId, string $requestId): ?WeeklyPlanGoogleDocsSource
    {
        return WeeklyPlanGoogleDocsSource::query()
            ->where('organization_id', $organizationId)
            ->where('request_id', $requestId)
            ->first();
    }

    private function resolveIdempotentPlan(
        WeeklyPlanGoogleDocsSource $source,
        string $payloadSha256,
    ): WeeklyPlan {
        if (! hash_equals($source->request_payload_sha256, $payloadSha256)) {
            throw new GoogleDocsSourceException(
                'GOOGLE_DOCS_REQUEST_CONFLICT',
                409,
                'Mã yêu cầu đã được sử dụng cho một nguồn Google Docs khác.',
            );
        }

        return $source->plan()->firstOrFail();
    }

    private function planForWeekExists(int $organizationId, string $weekStart): bool
    {
        return WeeklyPlan::query()
            ->where('organization_id', $organizationId)
            ->whereDate('week_start', $weekStart)
            ->exists();
    }

    private function assertFreshAuthorization(User $actor, int $organizationId): void
    {
        $membership = $this->scope->freshAuthorizedMembership(
            $actor,
            'weekly_plans.manage_draft',
        );
        if ($membership === null
            || (int) $membership->organization_id !== $organizationId) {
            throw new AccessDeniedHttpException;
        }
    }

    private function planExists(?QueryException $previous = null): GoogleDocsSourceException
    {
        return new GoogleDocsSourceException(
            'WEEKLY_PLAN_ALREADY_EXISTS',
            409,
            'Tổ chức đã có kế hoạch cho tuần này. Nguồn hiện có không được thay thế tự động.',
            $previous,
        );
    }

    /** @param array<string, mixed> $metadata */
    private function record(
        WeeklyPlan $plan,
        User $actor,
        string $event,
        array $metadata,
        CarbonImmutable $createdAt,
    ): void {
        WeeklyPlanEvent::query()->create([
            'weekly_plan_id' => $plan->id,
            'actor_id' => $actor->id,
            'event_type' => $event,
            'metadata' => $metadata,
            'created_at' => $createdAt,
        ]);
    }
}
