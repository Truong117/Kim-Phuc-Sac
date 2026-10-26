<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use App\Models\OrganizationGoogleDocsFolder;
use App\Models\OrganizationGoogleDocsFolderEvent;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDriveFileMetadataClient;
use App\Services\WeeklyPlanning\WeeklyPlanScopeService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Throwable;

final class OrganizationGoogleDocsFolderService
{
    private const GOOGLE_FOLDER_MIME_TYPE = 'application/vnd.google-apps.folder';

    public const EVENT_APPROVED = 'FOLDER_APPROVED';

    public const EVENT_REPLACED = 'FOLDER_REPLACED';

    public const EVENT_DISABLED = 'FOLDER_DISABLED';

    public function __construct(
        private readonly WeeklyPlanScopeService $scope,
        private readonly GoogleDriveFileMetadataClient $files,
    ) {}

    public function approve(User $actor, string $folderId): OrganizationGoogleDocsFolder
    {
        $membership = $this->authorizedMembership($actor);
        $folderId = GoogleDocsDocumentReference::validateDocumentId($folderId);
        $this->assertValidGoogleFolder($folderId);

        try {
            return DB::transaction(function () use ($actor, $membership, $folderId): OrganizationGoogleDocsFolder {
                $folder = OrganizationGoogleDocsFolder::query()
                    ->where('organization_id', $membership->organization_id)
                    ->lockForUpdate()
                    ->first();

                if (! $folder instanceof OrganizationGoogleDocsFolder) {
                    $folder = OrganizationGoogleDocsFolder::query()->create([
                        'organization_id' => $membership->organization_id,
                        'google_folder_id' => $folderId,
                        'is_active' => true,
                        'approved_by_user_id' => $actor->id,
                        'approved_at' => now(),
                    ]);
                    $this->recordEvent($folder, $actor, self::EVENT_APPROVED, null, $folderId);

                    return $folder->fresh();
                }

                if ($folder->google_folder_id === $folderId && $folder->is_active) {
                    return $folder;
                }

                $previousFolderId = $folder->google_folder_id;
                $eventType = $previousFolderId === $folderId
                    ? self::EVENT_APPROVED
                    : self::EVENT_REPLACED;

                $folder->forceFill([
                    'google_folder_id' => $folderId,
                    'is_active' => true,
                    'approved_by_user_id' => $actor->id,
                    'approved_at' => now(),
                ])->save();
                $this->recordEvent($folder, $actor, $eventType, $previousFolderId, $folderId);

                return $folder->fresh();
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                throw new GoogleDocsRegistryException(
                    'GOOGLE_DOCS_FOLDER_ALREADY_ASSIGNED',
                    409,
                    'Thư mục Google Drive đã được phê duyệt cho tổ chức khác.',
                );
            }

            throw $exception;
        }
    }

    public function disable(User $actor): OrganizationGoogleDocsFolder
    {
        $membership = $this->authorizedMembership($actor);

        return DB::transaction(function () use ($actor, $membership): OrganizationGoogleDocsFolder {
            $folder = OrganizationGoogleDocsFolder::query()
                ->where('organization_id', $membership->organization_id)
                ->lockForUpdate()
                ->first();

            if (! $folder instanceof OrganizationGoogleDocsFolder) {
                throw new GoogleDocsRegistryException(
                    'GOOGLE_DOCS_FOLDER_NOT_FOUND',
                    404,
                    'Tổ chức chưa có thư mục Google Drive được phê duyệt.',
                );
            }

            if (! $folder->is_active) {
                return $folder;
            }

            $folder->forceFill(['is_active' => false])->save();
            $this->recordEvent(
                $folder,
                $actor,
                self::EVENT_DISABLED,
                $folder->google_folder_id,
                null,
            );

            return $folder->fresh();
        });
    }

    private function authorizedMembership(User $actor): OrganizationMembership
    {
        $membership = $this->scope->membership($actor);
        if ($membership === null
            || ! $this->scope->allows($actor, 'weekly_plans.google_docs.configure')) {
            throw new AccessDeniedHttpException;
        }

        return $membership;
    }

    private function assertValidGoogleFolder(string $folderId): void
    {
        try {
            $metadata = $this->files->getFileMetadata(
                $folderId,
                max(1, (int) config('google_docs.timeout_seconds', 15)),
                max(1, (int) config('google_docs.metadata_max_response_bytes', 65536)),
            );
        } catch (GoogleDriveApiException $exception) {
            throw $this->folderVerificationFailure($exception);
        } catch (Throwable) {
            throw new GoogleDocsRegistryException(
                'GOOGLE_DOCS_FOLDER_VERIFICATION_UNAVAILABLE',
                503,
                'Không thể xác minh thư mục Google Drive vào lúc này.',
            );
        }

        if (($metadata['id'] ?? null) !== $folderId
            || ($metadata['mime_type'] ?? null) !== self::GOOGLE_FOLDER_MIME_TYPE) {
            throw new GoogleDocsRegistryException(
                'GOOGLE_DOCS_FOLDER_INVALID',
                422,
                'Google Drive folder ID không trỏ đến một thư mục hợp lệ.',
            );
        }
    }

    private function folderVerificationFailure(
        GoogleDriveApiException $exception,
    ): GoogleDocsRegistryException {
        if ($exception->statusCode === 404) {
            return new GoogleDocsRegistryException(
                'GOOGLE_DOCS_FOLDER_INVALID',
                422,
                'Google Drive folder ID không trỏ đến một thư mục hợp lệ.',
            );
        }

        if ($exception->statusCode === 429) {
            return new GoogleDocsRegistryException(
                'GOOGLE_DOCS_FOLDER_RATE_LIMITED',
                429,
                'Google đang giới hạn yêu cầu. Vui lòng thử lại sau.',
            );
        }

        if (in_array($exception->statusCode, [401, 403], true)) {
            return new GoogleDocsRegistryException(
                'GOOGLE_DOCS_FOLDER_ACCESS_DENIED',
                502,
                'Dịch vụ chưa được cấp quyền xác minh thư mục Google Drive này.',
            );
        }

        return new GoogleDocsRegistryException(
            'GOOGLE_DOCS_FOLDER_VERIFICATION_UNAVAILABLE',
            503,
            'Không thể xác minh thư mục Google Drive vào lúc này.',
        );
    }

    private function recordEvent(
        OrganizationGoogleDocsFolder $folder,
        User $actor,
        string $eventType,
        ?string $previousFolderId,
        ?string $currentFolderId,
    ): void {
        OrganizationGoogleDocsFolderEvent::query()->create([
            'folder_id' => $folder->id,
            'organization_id' => $folder->organization_id,
            'actor_user_id' => $actor->id,
            'event_type' => $eventType,
            'previous_google_folder_id' => $previousFolderId,
            'current_google_folder_id' => $currentFolderId,
        ]);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtoupper($exception->getMessage());

        return $sqlState === '23505'
            || $driverCode === 1062
            || ($driverCode === 19 && str_contains($message, 'UNIQUE'));
    }
}
