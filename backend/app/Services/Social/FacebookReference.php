<?php

namespace App\Services\Social;

final class FacebookReference
{
    public static function url(string $value): ?string
    {
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
        foreach (['story_fbid', 'id', 'v'] as $field) {
            if (isset($query[$field]) && is_string($query[$field]) && preg_match('/^[A-Za-z0-9_]+$/D', $query[$field])) {
                $identity[$field] = $query[$field];
            }
        }
        if ($path === '') {
            return null;
        }

        return 'https://facebook.com'.$path.($identity ? '?'.http_build_query($identity) : '');
    }

    public static function keys(string $value): array
    {
        $canonical = self::url($value);
        if ($canonical === null) {
            return [];
        }
        $keys = [$canonical];
        $url = parse_url($canonical);
        parse_str($url['query'] ?? '', $query);
        if (preg_match('~/(?:posts|videos|reel)/([0-9]+)$~D', $url['path'], $match)) {
            $keys[] = 'object:'.$match[1];
        }
        foreach (['v', 'story_fbid'] as $field) {
            if (isset($query[$field]) && ctype_digit($query[$field])) {
                $keys[] = 'object:'.$query[$field];
            }
        }

        return array_unique($keys);
    }
}
