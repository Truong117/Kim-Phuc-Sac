<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use App\Models\User;
use App\Services\WeeklyPlanning\WeeklyPlanScopeService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class GoogleDocsPreviewService
{
    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly GoogleDocsFolderAuthorizer $authorizer,
        private readonly GoogleDocsDocumentReader $reader,
    ) {}

    /** @return array<string, mixed> */
    public function preview(User $actor, string $documentUrl): array
    {
        $membership = $this->scope->membership($actor);
        if ($membership === null
            || ! $this->scope->allows($actor, 'weekly_plans.manage_draft')) {
            throw new AccessDeniedHttpException;
        }

        $documentId = GoogleDocsDocumentReference::documentIdFromUrl($documentUrl);

        try {
            $before = $this->authorizer->assertDocumentApproved(
                (int) $membership->organization_id,
                $documentId,
            );
        } catch (GoogleDocsAuthorizationException $exception) {
            throw $this->authorizationFailure($exception);
        }

        try {
            $snapshot = $this->reader->read($documentId);
        } catch (GoogleDocsReadException $exception) {
            throw $this->readFailure($exception);
        }

        try {
            $after = $this->authorizer->assertDocumentApproved(
                (int) $membership->organization_id,
                $documentId,
            );
        } catch (GoogleDocsAuthorizationException $exception) {
            if ($exception->errorCode === 'GOOGLE_DOCS_NOT_APPROVED') {
                throw $this->authorizationChanged();
            }

            throw $this->authorizationFailure($exception);
        }

        if ($before !== $after) {
            throw $this->authorizationChanged();
        }

        $tabs = array_map(fn (array $tab): array => [
            'tab_id' => $tab['tab_id'],
            'title' => $tab['title'],
            'parent_tab_id' => $tab['parent_tab_id'],
            'nesting_level' => $tab['nesting_level'],
            'order' => $tab['order'],
            'passage_count' => $tab['passage_count'],
            'character_count' => $tab['character_count'],
            'source_group' => $this->safeSourceGroup($tab['source_group']),
        ], $snapshot['tabs']);

        return [
            'title' => $snapshot['title'],
            'revision_id' => $snapshot['revision_id'],
            'snapshot_sha256' => $snapshot['snapshot_sha256'],
            'identity_verified' => false,
            'tab_count' => count($tabs),
            'passage_count' => array_sum(array_column($tabs, 'passage_count')),
            'character_count' => array_sum(array_column($tabs, 'character_count')),
            'tabs' => $tabs,
        ];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function safeSourceGroup(array $group): array
    {
        $evidence = is_array($group['evidence'] ?? null) ? $group['evidence'] : [];

        return [
            'version' => (int) ($group['version'] ?? 1),
            'key' => (string) ($group['key'] ?? ''),
            'title' => (string) ($group['title'] ?? ''),
            'origin' => 'GOOGLE_DOCS_TAB',
            'order' => (int) ($group['order'] ?? 0),
            'identity_verified' => false,
            'evidence' => [
                'type' => 'GOOGLE_DOCS_TAB',
                'tab_id' => is_string($evidence['tab_id'] ?? null) ? $evidence['tab_id'] : null,
                'parent_tab_id' => is_string($evidence['parent_tab_id'] ?? null)
                    ? $evidence['parent_tab_id']
                    : null,
                'nesting_level' => (int) ($evidence['nesting_level'] ?? 0),
                'index' => (int) ($evidence['index'] ?? 0),
            ],
        ];
    }

    private function authorizationFailure(
        GoogleDocsAuthorizationException $exception,
    ): GoogleDocsPreviewException {
        if ($exception->errorCode === 'GOOGLE_DOCS_NOT_APPROVED'
            || $exception->providerStatus === 404) {
            return new GoogleDocsPreviewException(
                'GOOGLE_DOCS_NOT_AVAILABLE',
                404,
                'Không thể truy cập tài liệu Google Docs trong phạm vi được phê duyệt.',
            );
        }

        if ($exception->providerStatus === 429) {
            return new GoogleDocsPreviewException(
                'GOOGLE_DOCS_RATE_LIMITED',
                429,
                'Google đang giới hạn yêu cầu. Vui lòng thử lại sau.',
            );
        }

        if (in_array($exception->providerStatus, [401, 403], true)) {
            return new GoogleDocsPreviewException(
                'GOOGLE_DOCS_PROVIDER_ACCESS_DENIED',
                502,
                'Dịch vụ chưa được cấp quyền đọc tài liệu này.',
            );
        }

        return new GoogleDocsPreviewException(
            'GOOGLE_DOCS_PROVIDER_UNAVAILABLE',
            503,
            'Dịch vụ Google Docs tạm thời không khả dụng.',
        );
    }

    private function readFailure(GoogleDocsReadException $exception): GoogleDocsPreviewException
    {
        return match ($exception->errorCode) {
            'GOOGLE_DOCS_NOT_FOUND' => new GoogleDocsPreviewException(
                'GOOGLE_DOCS_NOT_AVAILABLE',
                404,
                'Không thể truy cập tài liệu Google Docs trong phạm vi được phê duyệt.',
            ),
            'GOOGLE_DOCS_RATE_LIMITED' => new GoogleDocsPreviewException(
                'GOOGLE_DOCS_RATE_LIMITED',
                429,
                'Google đang giới hạn yêu cầu. Vui lòng thử lại sau.',
            ),
            'GOOGLE_DOCS_ACCESS_DENIED' => new GoogleDocsPreviewException(
                'GOOGLE_DOCS_PROVIDER_ACCESS_DENIED',
                502,
                'Dịch vụ chưa được cấp quyền đọc tài liệu này.',
            ),
            'GOOGLE_DOCS_RESPONSE_TOO_LARGE', 'GOOGLE_DOCS_LIMIT_EXCEEDED' => new GoogleDocsPreviewException(
                'GOOGLE_DOCS_LIMIT_EXCEEDED',
                422,
                'Tài liệu vượt quá giới hạn cho phép để xem trước.',
            ),
            'GOOGLE_DOCS_MALFORMED_RESPONSE' => new GoogleDocsPreviewException(
                'GOOGLE_DOCS_INVALID_PROVIDER_RESPONSE',
                502,
                'Google Docs trả về dữ liệu không hợp lệ.',
            ),
            default => new GoogleDocsPreviewException(
                'GOOGLE_DOCS_PROVIDER_UNAVAILABLE',
                503,
                'Dịch vụ Google Docs tạm thời không khả dụng.',
            ),
        };
    }

    private function authorizationChanged(): GoogleDocsPreviewException
    {
        return new GoogleDocsPreviewException(
            'GOOGLE_DOCS_AUTHORIZATION_CHANGED',
            409,
            'Quyền truy cập tài liệu đã thay đổi. Vui lòng thử lại.',
        );
    }
}
