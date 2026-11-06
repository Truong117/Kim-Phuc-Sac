<?php

namespace App\Services\Mail\Gmail;

class GmailConfiguration
{
    public function ready(): bool
    {
        $g = config('kps_mail.gmail');
        foreach (['client_id', 'client_secret', 'redirect_uri', 'expected_email'] as $key) {
            if (! is_string($g[$key] ?? null) || trim($g[$key]) === '') {
                return false;
            }
        }
        $redirect = parse_url($g['redirect_uri']);
        $frontend = parse_url((string) config('kps_mail.frontend_url'));
        $validUrl = fn ($url): bool => is_array($url) && isset($url['host']) && ! isset($url['user']) && ! isset($url['pass'])
            && (($url['scheme'] ?? '') === 'https' || (app()->environment(['local', 'testing']) && ($url['scheme'] ?? '') === 'http' && in_array($url['host'], ['localhost', '127.0.0.1'], true)));

        return $validUrl($redirect) && $validUrl($frontend)
            && ! isset($redirect['query']) && ! isset($redirect['fragment']) && ! isset($frontend['query']) && ! isset($frontend['fragment'])
            && ($redirect['path'] ?? '') === '/api/integrations/mail/google/callback'
            && filter_var($g['expected_email'], FILTER_VALIDATE_EMAIL) !== false;
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode([config('kps_mail.gmail.client_id'), config('kps_mail.gmail.redirect_uri'), config('kps_mail.frontend_url'), mb_strtolower((string) config('kps_mail.gmail.expected_email'))], JSON_THROW_ON_ERROR));
    }
}
