<?php

namespace App\Services\Integrations;

final class IntegrationUrl
{
    public static function identity(string $url, string $kind, ?string $platform): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $path = rtrim($parts['path'] ?? '', '/');
        parse_str($parts['query'] ?? '', $query);
        foreach (['access_token', 'token', 'code', 'client_secret', 'key'] as $key) {
            if (array_key_exists($key, $query)) {
                return null;
            }
        }
        if ($kind === 'drive') {
            return $host === 'drive.google.com' && preg_match('~^/drive/(?:u/\d+/)?folders/([A-Za-z0-9_-]+)$~D', $path, $match)
                ? 'drive:'.$match[1] : null;
        }
        $domains = match ($platform) {
            'tiktok' => ['tiktok.com'],
            'facebook' => ['facebook.com', 'fb.com'],
            'zalo' => ['zalo.me', 'zaloapp.com'],
            'metaAds' => ['adsmanager.facebook.com', 'business.facebook.com'],
            default => [],
        };
        $allowed = false;
        foreach ($domains as $domain) {
            $allowed = $allowed || $host === $domain || str_ends_with($host, '.'.$domain);
        }
        if (! $allowed || $path === '') {
            return null;
        }
        $identityQuery = [];
        foreach (['act', 'id'] as $key) {
            if (isset($query[$key])) {
                if (! is_string($query[$key]) || ! preg_match('/^[A-Za-z0-9_-]+$/D', $query[$key])) {
                    return null;
                }
                $identityQuery[$key] = $query[$key];
            }
        }
        $host = preg_replace('/^(www|m)\./', '', $host);
        if ($host === 'fb.com') {
            $host = 'facebook.com';
        }

        return $host.$path.($identityQuery ? '?'.http_build_query($identityQuery) : '');
    }
}
