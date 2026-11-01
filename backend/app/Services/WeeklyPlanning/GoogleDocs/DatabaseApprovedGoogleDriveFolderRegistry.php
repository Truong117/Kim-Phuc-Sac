<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use App\Models\OrganizationGoogleDocsFolder;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\ApprovedGoogleDriveFolderRegistry;

final class DatabaseApprovedGoogleDriveFolderRegistry implements ApprovedGoogleDriveFolderRegistry
{
    public function approvedFolderIdForOrganization(int $organizationId): ?string
    {
        if ($organizationId <= 0) {
            return null;
        }

        $folderId = OrganizationGoogleDocsFolder::query()
            ->where('organization_id', $organizationId)
            ->where('is_active', true)
            ->value('google_folder_id');

        return is_string($folderId) && $folderId !== '' ? $folderId : null;
    }
}
