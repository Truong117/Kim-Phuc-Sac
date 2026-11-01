<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleAuthorizedHttpClientFactory;
use Google\Client as GoogleClient;
use Google\Service\Docs;
use Google\Service\Drive;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use JsonException;
use Throwable;

final class GoogleServiceAccountHttpClientFactory implements GoogleAuthorizedHttpClientFactory
{
    private const ALLOWED_SCOPES = [
        Docs::DOCUMENTS_READONLY,
        Drive::DRIVE_METADATA_READONLY,
    ];

    public function __construct(
        private readonly ?GoogleClient $googleClient = null,
        private readonly ?ClientInterface $baseHttpClient = null,
    ) {}

    public function create(array $scopes, int $timeoutSeconds): ClientInterface
    {
        try {
            $scopes = $this->validateScopes($scopes);
            $credentials = $this->loadCredentials();
            $timeoutSeconds = max(1, $timeoutSeconds);
            $google = $this->googleClient ?? new GoogleClient;
            $http = $this->baseHttpClient ?? new GuzzleClient([
                'connect_timeout' => min($timeoutSeconds, 10),
                'timeout' => $timeoutSeconds,
                'http_errors' => false,
                'allow_redirects' => false,
                'verify' => true,
            ]);

            $google->setApplicationName((string) config(
                'google_docs.application_name',
                'KPS Internal System Google Docs Reader',
            ));
            $google->setAuthConfig($credentials);
            $google->setScopes($scopes);

            return $google->authorize($http);
        } catch (GoogleServiceAccountConfigurationException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GoogleServiceAccountConfigurationException;
        }
    }

    /** @param list<string> $scopes
     * @return list<string>
     */
    private function validateScopes(array $scopes): array
    {
        $scopes = array_values(array_unique($scopes));
        if ($scopes === []
            || count($scopes) > count(self::ALLOWED_SCOPES)
            || array_diff($scopes, self::ALLOWED_SCOPES) !== []) {
            throw new GoogleServiceAccountConfigurationException;
        }

        return $scopes;
    }

    /** @return array<string, mixed> */
    private function loadCredentials(): array
    {
        $configuredPath = config('google_docs.credentials_path');
        if (! is_string($configuredPath) || trim($configuredPath) === '') {
            throw new GoogleServiceAccountConfigurationException;
        }

        $realPath = realpath($configuredPath);
        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            throw new GoogleServiceAccountConfigurationException;
        }
        $forbiddenRoots = array_filter([
            realpath(base_path()),
            is_dir(dirname(base_path()).DIRECTORY_SEPARATOR.'.git')
                ? realpath(dirname(base_path()))
                : false,
        ], 'is_string');
        foreach ($forbiddenRoots as $forbiddenRoot) {
            if ($this->isInside($realPath, $forbiddenRoot)) {
                throw new GoogleServiceAccountConfigurationException;
            }
        }

        $maxBytes = max(1, (int) config('google_docs.credentials_max_bytes', 65536));
        $size = filesize($realPath);
        if (! is_int($size) || $size <= 0 || $size > $maxBytes) {
            throw new GoogleServiceAccountConfigurationException;
        }
        $contents = file_get_contents($realPath);
        if (! is_string($contents) || strlen($contents) > $maxBytes) {
            throw new GoogleServiceAccountConfigurationException;
        }

        try {
            $credentials = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new GoogleServiceAccountConfigurationException;
        } finally {
            unset($contents);
        }

        if (! is_array($credentials)
            || ($credentials['type'] ?? null) !== 'service_account'
            || ! $this->nonEmptyString($credentials['client_id'] ?? null)
            || ! $this->nonEmptyString($credentials['client_email'] ?? null)
            || ! $this->nonEmptyString($credentials['private_key'] ?? null)
            || ($credentials['token_uri'] ?? null) !== 'https://oauth2.googleapis.com/token') {
            throw new GoogleServiceAccountConfigurationException;
        }

        return $credentials;
    }

    private function nonEmptyString(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isInside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') {
            $path = mb_strtolower($path);
            $root = mb_strtolower($root);
        }

        return $path === $root || str_starts_with($path, $root.'/');
    }
}
