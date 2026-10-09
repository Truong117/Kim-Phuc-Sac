<?php

namespace Tests\Unit;

use App\Enums\DataScope;
use PHPUnit\Framework\TestCase;

class DataScopeTest extends TestCase
{
    public function test_scopes_are_ranked_from_narrowest_to_broadest(): void
    {
        $orderedScopes = [
            DataScope::SELF,
            DataScope::OWN,
            DataScope::TEAM,
            DataScope::DEPARTMENT,
            DataScope::LOCATION,
            DataScope::ORGANIZATION,
            DataScope::ALL,
        ];

        $this->assertSame(
            ['SELF', 'OWN', 'TEAM', 'DEPARTMENT', 'LOCATION', 'ORGANIZATION', 'ALL'],
            array_map(static fn (DataScope $scope): string => $scope->value, $orderedScopes),
        );

        for ($index = 0; $index < count($orderedScopes) - 1; $index++) {
            $this->assertLessThan(
                $orderedScopes[$index + 1]->rank(),
                $orderedScopes[$index]->rank(),
            );
        }
    }
}
