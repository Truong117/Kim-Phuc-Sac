<?php

namespace App\Support;

final class TidbStarterIdentity
{
    private const USERNAME_PATTERN = '/\A(?<prefix>[A-Za-z0-9]{1,64})\.(?<account>[A-Za-z0-9_][A-Za-z0-9_-]{0,63})\z/D';

    /**
     * @return array{username: string, prefix: string, account: string}|null
     */
    public static function parseUsername(string $username): ?array
    {
        $username = trim($username, " \t\n\r\0\x0B\"'");

        if (! preg_match(self::USERNAME_PATTERN, $username, $matches)) {
            return null;
        }

        return [
            'username' => $username,
            'prefix' => $matches['prefix'],
            'account' => $matches['account'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function validateDistinctInstances(
        string $stagingUsername,
        string $stagingConfirmedPrefix,
        string $productionUsername,
        string $productionConfirmedPrefix,
    ): array {
        $violations = [];
        $staging = self::parseUsername($stagingUsername);
        $production = self::parseUsername($productionUsername);

        if ($staging === null) {
            $violations[] = 'invalid_staging_username';
        } elseif (! hash_equals($stagingConfirmedPrefix, $staging['prefix'])) {
            $violations[] = 'staging_username_prefix_mismatch';
        }

        if ($production === null) {
            $violations[] = 'invalid_production_username';
        } elseif (! hash_equals($productionConfirmedPrefix, $production['prefix'])) {
            $violations[] = 'production_username_prefix_mismatch';
        }

        if ($stagingConfirmedPrefix === '' || $productionConfirmedPrefix === '') {
            $violations[] = 'missing_confirmed_username_prefix';
        } elseif (hash_equals($stagingConfirmedPrefix, $productionConfirmedPrefix)) {
            $violations[] = 'staging_and_production_prefix_match';
        }

        if ($staging !== null && $production !== null
            && hash_equals($staging['username'], $production['username'])) {
            $violations[] = 'staging_and_production_username_match';
        }

        return array_values(array_unique($violations));
    }

    public static function prefixFingerprint(string $prefix): string
    {
        return hash('sha256', $prefix);
    }
}
