<?php

namespace App\Services\Mail\Contracts;

interface MailOAuthProvider
{
    public function authorizationUrl(string $state, string $verifier): string;

    /** @return array{access_token: string, refresh_token: string, expires_in: int, email: string, subject: string} */
    public function exchange(string $code, string $verifier): array;

    /** @return array{access_token: string, expires_in: int} */
    public function refresh(string $refreshToken): array;
}
