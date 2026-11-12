<?php

namespace App\Services\Mail;

final class ReportEmailPolicy
{
    public static function reason(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'invalid_email';
        }
        $domain = substr(strrchr($email, '@'), 1);
        foreach (['local', 'localhost', 'test', 'invalid', 'example', 'example.com', 'example.org', 'example.net'] as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.'.$reserved)) {
                return 'placeholder';
            }
        }

        return null;
    }

    public static function hash(string $email): string
    {
        return hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));
    }
}
