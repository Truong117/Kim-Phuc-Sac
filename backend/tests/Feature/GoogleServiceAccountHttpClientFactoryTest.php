<?php

namespace Tests\Feature;

use App\Services\WeeklyPlanning\GoogleDocs\GoogleServiceAccountConfigurationException;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleServiceAccountHttpClientFactory;
use Google\Client as GoogleClient;
use Google\Service\Docs;
use Google\Service\Drive;
use GuzzleHttp\ClientInterface;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class GoogleServiceAccountHttpClientFactoryTest extends TestCase
{
    private ?string $credentialPath = null;

    protected function tearDown(): void
    {
        if ($this->credentialPath !== null && is_file($this->credentialPath)) {
            unlink($this->credentialPath);
        }

        parent::tearDown();
    }

    public function test_factory_loads_backend_secret_and_applies_only_readonly_scopes(): void
    {
        Log::spy();
        $credentials = $this->fakeCredentials();
        $credentialPath = tempnam(sys_get_temp_dir(), 'kps-google-');
        $this->assertIsString($credentialPath);
        $this->credentialPath = $credentialPath;
        file_put_contents($this->credentialPath, json_encode($credentials, JSON_THROW_ON_ERROR));
        config()->set([
            'google_docs.credentials_path' => $this->credentialPath,
            'google_docs.credentials_max_bytes' => 65536,
            'google_docs.application_name' => 'KPS test reader',
        ]);

        $baseHttp = Mockery::mock(ClientInterface::class);
        $authorizedHttp = Mockery::mock(ClientInterface::class);
        $google = Mockery::mock(GoogleClient::class);
        $google->shouldReceive('setApplicationName')->once()->with('KPS test reader');
        $google->shouldReceive('setAuthConfig')->once()->with(Mockery::on(
            fn (array $value): bool => $value['type'] === 'service_account'
                && $value['client_email'] === $credentials['client_email']
                && $value['private_key'] === $credentials['private_key'],
        ));
        $google->shouldReceive('setScopes')->once()->with([
            Docs::DOCUMENTS_READONLY,
            Drive::DRIVE_METADATA_READONLY,
        ]);
        $google->shouldReceive('authorize')->once()->with($baseHttp)->andReturn($authorizedHttp);

        $result = (new GoogleServiceAccountHttpClientFactory($google, $baseHttp))->create([
            Docs::DOCUMENTS_READONLY,
            Drive::DRIVE_METADATA_READONLY,
        ], 8);

        $this->assertSame($authorizedHttp, $result);
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_factory_rejects_broad_scope_missing_secret_and_repository_credentials_safely(): void
    {
        $factory = new GoogleServiceAccountHttpClientFactory;

        $this->assertConfigurationFails(
            fn () => $factory->create(['https://www.googleapis.com/auth/drive'], 5),
        );

        config()->set('google_docs.credentials_path', null);
        $this->assertConfigurationFails(
            fn () => $factory->create([Docs::DOCUMENTS_READONLY], 5),
        );

        config()->set('google_docs.credentials_path', base_path('composer.json'));
        $this->assertConfigurationFails(
            fn () => $factory->create([Docs::DOCUMENTS_READONLY], 5),
        );
    }

    /** @return array<string, string> */
    private function fakeCredentials(): array
    {
        return [
            'type' => 'service_account',
            'client_id' => 'synthetic-client-id',
            'client_email' => 'synthetic-reader@example.invalid',
            'private_key' => 'synthetic-private-key-never-used',
            'token_uri' => 'https://oauth2.googleapis.com/token',
        ];
    }

    /** @param callable(): mixed $callback */
    private function assertConfigurationFails(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Unsafe configuration should fail closed.');
        } catch (GoogleServiceAccountConfigurationException $exception) {
            $this->assertSame(
                'Google service account is not safely configured.',
                $exception->getMessage(),
            );
            $this->assertStringNotContainsString('synthetic', $exception->getMessage());
            $this->assertStringNotContainsString('composer.json', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }
}
