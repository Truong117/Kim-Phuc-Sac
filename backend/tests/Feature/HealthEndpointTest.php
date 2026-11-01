<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_returns_service_status(): void
    {
        config(['release.revision' => str_repeat('a', 40), 'release.environment' => 'staging']);
        $response = $this->getJson('/api/health');

        $response
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'kps-backend',
                'revision' => str_repeat('a', 40),
                'environment' => 'staging',
            ]);
    }
}
