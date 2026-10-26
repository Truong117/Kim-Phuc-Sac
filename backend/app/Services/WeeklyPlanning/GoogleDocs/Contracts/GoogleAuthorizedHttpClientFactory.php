<?php

namespace App\Services\WeeklyPlanning\GoogleDocs\Contracts;

use GuzzleHttp\ClientInterface;

interface GoogleAuthorizedHttpClientFactory
{
    /** @param list<string> $scopes */
    public function create(array $scopes, int $timeoutSeconds): ClientInterface;
}
