<?php

namespace App\Services\WeeklyPlanning\GoogleDocs\Contracts;

interface ApprovedGoogleDriveFolderRegistry
{
    public function approvedFolderIdForOrganization(int $organizationId): ?string;
}
