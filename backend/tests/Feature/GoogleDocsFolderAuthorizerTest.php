<?php

namespace Tests\Feature;

use App\Services\WeeklyPlanning\GoogleDocs\Contracts\ApprovedGoogleDriveFolderRegistry;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleDriveFileMetadataClient;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsAuthorizationException;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsFolderAuthorizer;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDriveApiException;
use Tests\TestCase;

class GoogleDocsFolderAuthorizerTest extends TestCase
{
    private const DOCUMENT_ID = 'KpsGoogleDocument_1234567890AbCdEfGhIj';

    private const FOLDER_A = 'KpsApprovedFolder_A_1234567890AbCdEf';

    private const FOLDER_B = 'KpsApprovedFolder_B_1234567890AbCdEf';

    public function test_document_directly_in_the_organizations_folder_is_approved(): void
    {
        $files = new FakeGoogleDriveFileMetadataClient($this->metadata([self::FOLDER_A]));
        $authorizer = $this->authorizer($files);

        $authorizer->assertDocumentApproved(101, self::DOCUMENT_ID);

        $this->assertSame(self::DOCUMENT_ID, $files->lastFileId);
        $this->assertSame(15, $files->lastTimeoutSeconds);
        $this->assertSame(65536, $files->lastMaxResponseBytes);
    }

    public function test_outside_folder_wrong_mime_missing_parent_and_cross_organization_fail_closed(): void
    {
        $this->assertDenied($this->metadata([self::FOLDER_B]), 101);
        $this->assertDenied($this->metadata([self::FOLDER_A], 'application/pdf'), 101);
        $this->assertDenied($this->metadata([]), 101);
        $this->assertDenied($this->metadata([self::FOLDER_A, self::FOLDER_B]), 101);
        $this->assertDenied($this->metadata([self::FOLDER_A]), 202);
        $mismatchedId = $this->metadata([self::FOLDER_A]);
        $mismatchedId['id'] = 'DifferentGoogleDocument_1234567890AbCd';
        $this->assertDenied($mismatchedId, 101);
    }

    public function test_unknown_organization_and_provider_failure_are_indistinguishable_and_safe(): void
    {
        $files = new FakeGoogleDriveFileMetadataClient($this->metadata([self::FOLDER_A]));
        try {
            $this->authorizer($files)->assertDocumentApproved(303, self::DOCUMENT_ID);
            $this->fail('An organization without an approved folder must be denied.');
        } catch (GoogleDocsAuthorizationException $exception) {
            $this->assertSame('GOOGLE_DOCS_NOT_APPROVED', $exception->errorCode);
            $this->assertNull($files->lastFileId);
        }

        $providerFailure = new FakeGoogleDriveFileMetadataClient(
            error: new GoogleDriveApiException(403),
        );
        try {
            $this->authorizer($providerFailure)->assertDocumentApproved(101, self::DOCUMENT_ID);
            $this->fail('Provider failure must fail closed.');
        } catch (GoogleDocsAuthorizationException $exception) {
            $this->assertSame('GOOGLE_DOCS_AUTHORIZATION_UNAVAILABLE', $exception->errorCode);
            $this->assertSame(
                'Google document is not approved for this organization.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString(self::DOCUMENT_ID, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    /** @param list<string> $parents
     * @return array{id: string, mime_type: string, parents: list<string>}
     */
    private function metadata(
        array $parents,
        string $mimeType = 'application/vnd.google-apps.document',
    ): array {
        return [
            'id' => self::DOCUMENT_ID,
            'mime_type' => $mimeType,
            'parents' => $parents,
        ];
    }

    /** @param array{id: string, mime_type: string, parents: list<string>} $metadata */
    private function assertDenied(array $metadata, int $organizationId): void
    {
        try {
            $this->authorizer(new FakeGoogleDriveFileMetadataClient($metadata))
                ->assertDocumentApproved($organizationId, self::DOCUMENT_ID);
            $this->fail('The document must be denied.');
        } catch (GoogleDocsAuthorizationException $exception) {
            $this->assertSame('GOOGLE_DOCS_NOT_APPROVED', $exception->errorCode);
            $this->assertSame(
                'Google document is not approved for this organization.',
                $exception->getMessage(),
            );
        }
    }

    private function authorizer(
        GoogleDriveFileMetadataClient $files,
    ): GoogleDocsFolderAuthorizer {
        return new GoogleDocsFolderAuthorizer(
            new FakeApprovedGoogleDriveFolderRegistry([
                101 => self::FOLDER_A,
                202 => self::FOLDER_B,
            ]),
            $files,
        );
    }
}

final class FakeApprovedGoogleDriveFolderRegistry implements ApprovedGoogleDriveFolderRegistry
{
    /** @param array<int, string> $folders */
    public function __construct(private readonly array $folders) {}

    public function approvedFolderIdForOrganization(int $organizationId): ?string
    {
        return $this->folders[$organizationId] ?? null;
    }
}

final class FakeGoogleDriveFileMetadataClient implements GoogleDriveFileMetadataClient
{
    public ?string $lastFileId = null;

    public ?int $lastTimeoutSeconds = null;

    public ?int $lastMaxResponseBytes = null;

    /** @param array{id: string, mime_type: string, parents: list<string>} $metadata */
    public function __construct(
        private readonly array $metadata = [],
        private readonly ?GoogleDriveApiException $error = null,
    ) {}

    public function getFileMetadata(
        string $fileId,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array {
        $this->lastFileId = $fileId;
        $this->lastTimeoutSeconds = $timeoutSeconds;
        $this->lastMaxResponseBytes = $maxResponseBytes;
        if ($this->error !== null) {
            throw $this->error;
        }

        return $this->metadata;
    }
}
