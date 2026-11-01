<?php

namespace Tests\Unit;

use App\Support\TidbStarterIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TidbStarterIdentityTest extends TestCase
{
    public function test_distinct_prefixes_allow_a_shared_starter_gateway(): void
    {
        $this->assertSame([], TidbStarterIdentity::validateDistinctInstances(
            'StagePrefix123.root',
            'StagePrefix123',
            'ProdPrefix456.root',
            'ProdPrefix456',
        ));
    }

    #[DataProvider('unsafeIdentityProvider')]
    public function test_invalid_or_ambiguous_instance_identities_fail_closed(
        string $stagingUsername,
        string $stagingPrefix,
        string $productionUsername,
        string $productionPrefix,
        string $expectedViolation,
    ): void {
        $this->assertContains($expectedViolation, TidbStarterIdentity::validateDistinctInstances(
            $stagingUsername,
            $stagingPrefix,
            $productionUsername,
            $productionPrefix,
        ));
    }

    /**
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function unsafeIdentityProvider(): array
    {
        return [
            'same prefix on shared gateway' => [
                'SamePrefix.root',
                'SamePrefix',
                'SamePrefix.admin',
                'SamePrefix',
                'staging_and_production_prefix_match',
            ],
            'staging prefix does not match username' => [
                'ActualStage.root',
                'ClaimedStage',
                'ProdPrefix.root',
                'ProdPrefix',
                'staging_username_prefix_mismatch',
            ],
            'production prefix does not match username' => [
                'StagePrefix.root',
                'StagePrefix',
                'ActualProd.root',
                'ClaimedProd',
                'production_username_prefix_mismatch',
            ],
            'missing separator' => [
                'StagePrefixRoot',
                'StagePrefix',
                'ProdPrefix.root',
                'ProdPrefix',
                'invalid_staging_username',
            ],
            'unsafe username characters' => [
                'StagePrefix.root@example',
                'StagePrefix',
                'ProdPrefix.root',
                'ProdPrefix',
                'invalid_staging_username',
            ],
            'missing confirmed prefix' => [
                'StagePrefix.root',
                '',
                'ProdPrefix.root',
                'ProdPrefix',
                'missing_confirmed_username_prefix',
            ],
        ];
    }
}
