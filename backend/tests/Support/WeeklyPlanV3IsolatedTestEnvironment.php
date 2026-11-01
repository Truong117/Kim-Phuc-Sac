<?php

namespace Tests\Support;

use LogicException;

final class WeeklyPlanV3IsolatedTestEnvironment
{
    public const E2E_DATABASE = 'storage/framework/testing/weekly-plan-v3-e2e/database/weekly-plan-v3-e2e.sqlite';

    public const E2E_STORAGE_DISK = 'weekly_plan_e2e';

    public const TIDB_DATABASE_PATTERN = '/\Akps_v3_phase7b1_[a-z0-9_]+\z/';

    public const TIDB_PINNED_VERSION = 'v8.5.8';

    /**
     * @param  array<string, mixed>  $environment
     * @return list<string>
     */
    public static function e2eViolations(array $environment): array
    {
        $violations = self::commonViolations($environment, 'KPS_V3_E2E_TEST_ENABLED');

        if (self::value($environment, 'DB_CONNECTION') !== 'sqlite') {
            $violations[] = 'Browser E2E must use SQLite.';
        }

        if (self::normalizedPath(self::value($environment, 'DB_DATABASE')) !== self::E2E_DATABASE) {
            $violations[] = 'Browser E2E must use the exact isolated SQLite path.';
        }

        if (self::value($environment, 'WEEKLY_PLAN_STORAGE_DISK') !== self::E2E_STORAGE_DISK) {
            $violations[] = 'Browser E2E must use the isolated Weekly Planning storage disk.';
        }

        foreach (['APP_URL', 'FRONTEND_URL'] as $urlKey) {
            if (! self::isLoopbackUrl(self::value($environment, $urlKey))) {
                $violations[] = $urlKey.' must use an HTTP loopback origin.';
            }
        }

        $statefulDomains = array_filter(array_map(
            'trim',
            explode(',', self::value($environment, 'SANCTUM_STATEFUL_DOMAINS')),
        ));
        if ($statefulDomains === [] || array_filter(
            $statefulDomains,
            static fn (string $domain): bool => ! self::isLoopbackAuthority($domain),
        ) !== []) {
            $violations[] = 'SANCTUM_STATEFUL_DOMAINS must contain only loopback authorities.';
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param  array<string, mixed>  $environment
     * @return list<string>
     */
    public static function tidbViolations(array $environment, ?string $serverVersion): array
    {
        return array_values(array_unique(array_merge(
            self::tidbConfigurationViolations($environment),
            self::tidbServerViolations($serverVersion),
        )));
    }

    /**
     * @param  array<string, mixed>  $environment
     * @return list<string>
     */
    public static function tidbConfigurationViolations(array $environment): array
    {
        $violations = self::commonViolations($environment, 'KPS_V3_TIDB_TEST_ENABLED');

        if (self::value($environment, 'DB_CONNECTION') !== 'mysql') {
            $violations[] = 'TiDB tests must use the MySQL-compatible connection.';
        }

        if (! self::isLoopbackHost(self::value($environment, 'DB_HOST'))) {
            $violations[] = 'TiDB tests must use a loopback host.';
        }

        if (self::value($environment, 'DB_PORT') !== '4000') {
            $violations[] = 'TiDB tests must use the dedicated local port 4000.';
        }

        if (preg_match(self::TIDB_DATABASE_PATTERN, self::value($environment, 'DB_DATABASE')) !== 1) {
            $violations[] = 'TiDB tests must use a kps_v3_phase7b1_* throwaway database.';
        }

        if (self::value($environment, 'DB_USERNAME') !== 'kps_v3_phase7b1') {
            $violations[] = 'TiDB tests must use the dedicated synthetic account.';
        }

        if (self::value($environment, 'DB_PASSWORD') === '') {
            $violations[] = 'TiDB tests require a non-empty local-only password.';
        }

        if (self::value($environment, 'KPS_V3_TIDB_PINNED_VERSION') !== self::TIDB_PINNED_VERSION) {
            $violations[] = 'The configured TiDB version does not match the reviewed pin.';
        }

        return array_values(array_unique($violations));
    }

    /** @return list<string> */
    public static function tidbServerViolations(?string $serverVersion): array
    {
        $version = strtolower(trim((string) $serverVersion));
        $pinnedVersionPattern = preg_quote(strtolower(self::TIDB_PINNED_VERSION), '/');
        if ($version === ''
            || ! str_contains($version, 'tidb')
            || preg_match('/(?:^|[^0-9])'.$pinnedVersionPattern.'(?:[^0-9]|$)/', $version) !== 1) {
            return ['The server must identify itself as the pinned TiDB version.'];
        }

        return [];
    }

    /** @param list<string> $violations */
    public static function assertSafe(array $violations): void
    {
        if ($violations !== []) {
            throw new LogicException(
                "Unsafe Weekly Planning V3 external test environment:\n- ".implode("\n- ", $violations),
            );
        }
    }

    /**
     * @param  array<string, mixed>  $environment
     * @return list<string>
     */
    private static function commonViolations(array $environment, string $explicitGate): array
    {
        $violations = [];

        if (self::value($environment, $explicitGate) !== 'true') {
            $violations[] = $explicitGate.' must be explicitly true.';
        }

        if (self::value($environment, 'APP_ENV') !== 'local') {
            $violations[] = 'External test infrastructure may run only with APP_ENV=local.';
        }

        if (self::value($environment, 'KPS_DATABASE_ENVIRONMENT') !== 'development') {
            $violations[] = 'External test infrastructure requires the development database marker.';
        }

        if (self::value($environment, 'WEEKLY_PLAN_WORKFLOW_V3_ENABLED') !== 'true') {
            $violations[] = 'V3 must be enabled explicitly only in the isolated process.';
        }

        if (self::value($environment, 'FILESYSTEM_DISK') !== self::E2E_STORAGE_DISK) {
            $violations[] = 'External test infrastructure must use the isolated default storage disk.';
        }

        if (self::value($environment, 'SESSION_DRIVER') !== 'database') {
            $violations[] = 'External test infrastructure must keep sessions in the isolated database.';
        }

        if (self::value($environment, 'CACHE_STORE') !== 'database') {
            $violations[] = 'External test infrastructure must keep cache data in the isolated database.';
        }

        if (self::value($environment, 'MAIL_MAILER') !== 'array') {
            $violations[] = 'External test infrastructure must use the in-memory mailer.';
        }

        if (self::value($environment, 'QUEUE_CONNECTION') !== 'sync') {
            $violations[] = 'External test infrastructure must use the synchronous queue.';
        }

        if (self::value($environment, 'DB_URL') !== '') {
            $violations[] = 'External test infrastructure must not use DB_URL.';
        }

        foreach ([
            'RENDER',
            'RENDER_SERVICE_ID',
            'VERCEL',
            'CI',
        ] as $hostedMarker) {
            if (self::value($environment, $hostedMarker) !== '') {
                $violations[] = $hostedMarker.' must be absent from the local external test process.';
            }
        }

        return $violations;
    }

    /** @param array<string, mixed> $environment */
    private static function value(array $environment, string $key): string
    {
        $value = $environment[$key] ?? '';

        return is_scalar($value) ? strtolower(trim((string) $value)) : '';
    }

    private static function normalizedPath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return strtolower($path);
    }

    private static function isLoopbackUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'http'
            && self::isLoopbackHost($parts['host'] ?? '')
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && (! isset($parts['path']) || $parts['path'] === '' || $parts['path'] === '/');
    }

    private static function isLoopbackAuthority(string $authority): bool
    {
        $parts = parse_url('http://'.$authority);

        return is_array($parts)
            && self::isLoopbackHost($parts['host'] ?? '')
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['path'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment']);
    }

    private static function isLoopbackHost(string $host): bool
    {
        return in_array(strtolower(trim($host)), ['127.0.0.1', 'localhost', '::1'], true);
    }
}
