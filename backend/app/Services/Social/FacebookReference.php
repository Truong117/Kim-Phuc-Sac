<?php

namespace App\Services\Social;

final class FacebookReference
{
    public static function url(string $value): ?string
    {
        $value = trim($value);
        if (strlen($value) > 2048 || str_contains($value, '\\') || preg_match('/[\x00-\x20]/', $value)) {
            return null;
        }
        $url = parse_url(trim($value));
        if (! is_array($url) || ($url['scheme'] ?? '') !== 'https' || isset($url['user']) || isset($url['pass']) || isset($url['port'])) {
            return null;
        }
        $host = preg_replace('/^(www|m|web)\./', '', strtolower($url['host'] ?? ''));
        if (! in_array($host, ['facebook.com', 'fb.com'], true)) {
            return null;
        }
        $path = rtrim($url['path'] ?? '', '/');
        parse_str($url['query'] ?? '', $query);
        $identity = [];
        foreach (['story_fbid', 'id', 'v', 'fbid'] as $field) {
            if (isset($query[$field]) && is_string($query[$field]) && preg_match('/^[A-Za-z0-9_]+$/D', $query[$field])) {
                $identity[$field] = $query[$field];
            }
        }
        if ($path === '') {
            return null;
        }

        return 'https://facebook.com'.$path.($identity ? '?'.http_build_query($identity) : '');
    }

    public static function isShare(string $value): bool
    {
        $url = self::url($value);

        return $url !== null && (bool) preg_match('~^/share/(?:[rpv]/)?[A-Za-z0-9]+$~D', parse_url($url, PHP_URL_PATH));
    }

    public static function postKeys(array $post): array
    {
        $keys = self::keys((string) ($post['permalink_url'] ?? ''));
        if (preg_match('/^[0-9]+_([0-9]+)$/D', (string) ($post['id'] ?? ''), $match)) {
            $keys[] = 'object:'.$match[1];
        }
        foreach ($post['attachments']['data'] ?? [] as $attachment) {
            if (isset($attachment['target']['id']) && ctype_digit((string) $attachment['target']['id'])) {
                $keys[] = 'object:'.$attachment['target']['id'];
            }
        }

        return array_unique($keys);
    }

    public static function keys(string $value): array
    {
        $canonical = self::url($value);
        if ($canonical === null) {
            return [];
        }
        $url = parse_url($canonical);
        parse_str($url['query'] ?? '', $query);
        $path = $url['path'];
        $postPath = preg_match('~^/(?:[^/]+/)?(?:posts|videos|reel)/(?:[0-9]+|pfbid[A-Za-z0-9]+)$~D', $path);
        $queryPost = in_array($path, ['/permalink.php', '/story.php'], true) && isset($query['story_fbid']);
        $video = in_array($path, ['/watch', '/video.php'], true) && isset($query['v']);
        $photo = in_array($path, ['/photo', '/photo.php'], true) && isset($query['fbid']);
        if (! $postPath && ! $queryPost && ! $video && ! $photo) {
            return [];
        }
        $keys = [$canonical];
        if (preg_match('~/(?:posts|videos|reel)/([0-9]+)$~D', $url['path'], $match)) {
            $keys[] = 'object:'.$match[1];
        }
        foreach (['v', 'story_fbid', 'fbid'] as $field) {
            if (isset($query[$field]) && ctype_digit($query[$field])) {
                $keys[] = 'object:'.$query[$field];
            }
        }

        return array_unique($keys);
    }
}
