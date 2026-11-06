<?php

namespace App\Services\Mail\Gmail;

use App\Services\Mail\Contracts\MailOAuthProvider;
use App\Services\Mail\MailDeliveryException;
use Illuminate\Support\Facades\Http;
use Throwable;

class GmailOAuthProvider implements MailOAuthProvider
{
    public const SEND_SCOPE = 'https://www.googleapis.com/auth/gmail.send';

    public function authorizationUrl(string $state, string $verifier): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('kps_mail.gmail.client_id'), 'redirect_uri' => config('kps_mail.gmail.redirect_uri'),
            'response_type' => 'code', 'scope' => 'openid email '.self::SEND_SCOPE,
            'state' => $state, 'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256', 'access_type' => 'offline', 'prompt' => 'consent select_account',
            'login_hint' => config('kps_mail.gmail.expected_email'), 'include_granted_scopes' => 'false',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function exchange(string $code, string $verifier): array
    {
        try {
            $response = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => config('kps_mail.gmail.redirect_uri')]);
            $scopes = preg_split('/\s+/', (string) ($response['scope'] ?? '')) ?: [];
            if (! in_array(self::SEND_SCOPE, $scopes, true) || collect($scopes)->contains(fn ($scope) => str_contains($scope, '/auth/gmail.') && $scope !== self::SEND_SCOPE)
                || in_array('https://mail.google.com/', $scopes, true) || ! is_string($response['refresh_token'] ?? null) || $response['refresh_token'] === '') {
                throw new MailDeliveryException('GMAIL_SCOPE_OR_REFRESH_MISSING');
            }
            $identity = Http::withToken($response['access_token'])->acceptJson()->connectTimeout(5)->timeout(15)
                ->withOptions(['allow_redirects' => false])->get('https://openidconnect.googleapis.com/v1/userinfo');
            $user = $identity->json();
            if (! $identity->successful() || ! is_array($user) || ($user['email_verified'] ?? false) !== true
                || ! is_string($user['sub'] ?? null) || $user['sub'] === ''
                || mb_strtolower((string) ($user['email'] ?? '')) !== mb_strtolower((string) config('kps_mail.gmail.expected_email'))) {
                throw new MailDeliveryException('GMAIL_ACCOUNT_MISMATCH');
            }

            return ['access_token' => $response['access_token'], 'refresh_token' => $response['refresh_token'], 'expires_in' => $response['expires_in'], 'email' => mb_strtolower($user['email']), 'subject' => $user['sub']];
        } catch (MailDeliveryException $e) {
            throw $e;
        } catch (Throwable) {
            throw new MailDeliveryException('GMAIL_OAUTH_FAILED');
        }
    }

    public function refresh(string $refreshToken): array
    {
        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    private function tokenRequest(array $values): array
    {
        try {
            $response = Http::asForm()->acceptJson()->connectTimeout(5)->timeout(15)->withOptions(['allow_redirects' => false])
                ->post('https://oauth2.googleapis.com/token', $values + ['client_id' => config('kps_mail.gmail.client_id'), 'client_secret' => config('kps_mail.gmail.client_secret')]);
            $data = $response->json();
            if (! $response->successful() || ! is_array($data) || ! is_string($data['access_token'] ?? null) || $data['access_token'] === ''
                || ! is_numeric($data['expires_in'] ?? null) || (int) $data['expires_in'] <= 0) {
                throw new MailDeliveryException('GMAIL_TOKEN_UNAVAILABLE', 'failed', ($data['error'] ?? '') === 'invalid_grant');
            }

            return $data;
        } catch (MailDeliveryException $e) {
            throw $e;
        } catch (Throwable) {
            throw new MailDeliveryException('GMAIL_TOKEN_UNAVAILABLE');
        }
    }
}
