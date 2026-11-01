<?php

namespace Tests\Feature;

use App\Services\WeeklyPlanning\GoogleDocs\BoundedGoogleJsonTransport;
use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleAuthorizedHttpClientFactory;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsApiException;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDocsApiTransport;
use App\Services\WeeklyPlanning\GoogleDocs\GoogleDriveFileMetadataTransport;
use Google\Service\Docs;
use Google\Service\Drive;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GoogleDocsApiTransportTest extends TestCase
{
    private const DOCUMENT_ID = 'KpsGoogleDocument_1234567890AbCdEfGhIj';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_docs_transport_uses_fixed_endpoint_tabs_scope_tls_and_bounded_streaming(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->once()
            ->with(
                'GET',
                'https://docs.googleapis.com/v1/documents/'.self::DOCUMENT_ID,
                Mockery::on(function (array $options): bool {
                    $this->assertSame(['includeTabsContent' => 'true'], $options['query']);
                    $this->assertSame(7, $options['timeout']);
                    $this->assertSame(7, $options['connect_timeout']);
                    $this->assertFalse($options['http_errors']);
                    $this->assertFalse($options['allow_redirects']);
                    $this->assertTrue($options['verify']);
                    $this->assertTrue($options['stream']);
                    $this->assertSame('application/json', $options['headers']['Accept']);

                    return true;
                }),
            )
            ->andReturn(new Response(200, [], json_encode([
                'documentId' => self::DOCUMENT_ID,
                'title' => 'Synthetic plan',
                'tabs' => [],
            ], JSON_THROW_ON_ERROR)));
        $factory = new FakeGoogleAuthorizedHttpClientFactory($http);
        $transport = $this->docsTransport($factory);

        $document = $transport->getDocument(self::DOCUMENT_ID, true, 7, 4096);

        $this->assertSame(self::DOCUMENT_ID, $document['documentId']);
        $this->assertSame([Docs::DOCUMENTS_READONLY], $factory->lastScopes);
        $this->assertSame(7, $factory->lastTimeoutSeconds);
    }

    public function test_docs_transport_rejects_disabling_tab_content_before_http(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldNotReceive('request');
        $factory = new FakeGoogleAuthorizedHttpClientFactory($http);

        try {
            $this->docsTransport($factory)->getDocument(self::DOCUMENT_ID, false, 7, 4096);
            $this->fail('The transport must require tab content.');
        } catch (GoogleDocsApiException $exception) {
            $this->assertSame('INVALID_REQUEST', $exception->reason);
            $this->assertNull($factory->lastScopes);
        }
    }

    #[DataProvider('providerFailureStatuses')]
    public function test_provider_statuses_are_safe_and_never_expose_the_response(
        int $status,
    ): void {
        Log::spy();
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')->once()->andReturn(
            new Response($status, [], 'sensitive provider payload must never escape'),
        );

        try {
            $this->docsTransport(new FakeGoogleAuthorizedHttpClientFactory($http))
                ->getDocument(self::DOCUMENT_ID, true, 5, 4096);
            $this->fail('The provider failure must be rejected.');
        } catch (GoogleDocsApiException $exception) {
            $this->assertSame($status, $exception->statusCode);
            $this->assertSame('Google Docs request failed.', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive', $exception->getMessage());
        }
        Log::shouldNotHaveReceived('debug');
        Log::shouldNotHaveReceived('info');
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    /** @return iterable<string, array{int}> */
    public static function providerFailureStatuses(): iterable
    {
        yield 'unauthenticated' => [401];
        yield 'forbidden' => [403];
        yield 'not found' => [404];
        yield 'rate limited' => [429];
        yield 'server error' => [500];
        yield 'service unavailable' => [503];
    }

    public function test_timeout_malformed_and_oversized_responses_fail_closed(): void
    {
        $timeoutClient = Mockery::mock(ClientInterface::class);
        $timeoutClient->shouldReceive('request')->once()->andThrow(new ConnectException(
            'sensitive network details',
            new Request('GET', 'https://docs.googleapis.com'),
        ));
        $this->assertTransportReason($timeoutClient, 'TIMEOUT');

        $malformedClient = Mockery::mock(ClientInterface::class);
        $malformedClient->shouldReceive('request')->once()->andReturn(new Response(200, [], '{invalid-json'));
        $this->assertTransportReason($malformedClient, 'MALFORMED_RESPONSE');

        $declaredOversizeClient = Mockery::mock(ClientInterface::class);
        $declaredOversizeClient->shouldReceive('request')->once()->andReturn(
            new Response(200, ['Content-Length' => '4097'], '{}'),
        );
        $this->assertTransportReason($declaredOversizeClient, 'RESPONSE_TOO_LARGE');

        $streamOversizeClient = Mockery::mock(ClientInterface::class);
        $streamOversizeClient->shouldReceive('request')->once()->andReturn(
            new Response(200, [], json_encode(['data' => str_repeat('x', 5000)], JSON_THROW_ON_ERROR)),
        );
        $this->assertTransportReason($streamOversizeClient, 'RESPONSE_TOO_LARGE');
    }

    public function test_drive_metadata_transport_requests_only_minimum_fields_with_readonly_scope(): void
    {
        $http = Mockery::mock(ClientInterface::class);
        $http->shouldReceive('request')
            ->once()
            ->with(
                'GET',
                'https://www.googleapis.com/drive/v3/files/'.self::DOCUMENT_ID,
                Mockery::on(function (array $options): bool {
                    $this->assertSame([
                        'fields' => 'id,mimeType,parents',
                        'supportsAllDrives' => 'true',
                    ], $options['query']);
                    $this->assertTrue($options['verify']);
                    $this->assertFalse($options['allow_redirects']);

                    return true;
                }),
            )
            ->andReturn(new Response(200, [], json_encode([
                'id' => self::DOCUMENT_ID,
                'mimeType' => 'application/vnd.google-apps.document',
                'parents' => ['KpsApprovedFolder_1234567890AbCdEf'],
            ], JSON_THROW_ON_ERROR)));
        $factory = new FakeGoogleAuthorizedHttpClientFactory($http);
        $transport = new GoogleDriveFileMetadataTransport(new BoundedGoogleJsonTransport($factory));

        $metadata = $transport->getFileMetadata(self::DOCUMENT_ID, 6, 65536);

        $this->assertSame(self::DOCUMENT_ID, $metadata['id']);
        $this->assertSame(['KpsApprovedFolder_1234567890AbCdEf'], $metadata['parents']);
        $this->assertSame([Drive::DRIVE_METADATA_READONLY], $factory->lastScopes);
    }

    private function assertTransportReason(ClientInterface $http, string $reason): void
    {
        try {
            $this->docsTransport(new FakeGoogleAuthorizedHttpClientFactory($http))
                ->getDocument(self::DOCUMENT_ID, true, 5, 4096);
            $this->fail("The transport should fail with {$reason}.");
        } catch (GoogleDocsApiException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame('Google Docs request failed.', $exception->getMessage());
            $this->assertStringNotContainsString('sensitive', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    private function docsTransport(
        GoogleAuthorizedHttpClientFactory $factory,
    ): GoogleDocsApiTransport {
        return new GoogleDocsApiTransport(new BoundedGoogleJsonTransport($factory));
    }
}

final class FakeGoogleAuthorizedHttpClientFactory implements GoogleAuthorizedHttpClientFactory
{
    /** @var list<string>|null */
    public ?array $lastScopes = null;

    public ?int $lastTimeoutSeconds = null;

    public function __construct(private readonly ClientInterface $client) {}

    public function create(array $scopes, int $timeoutSeconds): ClientInterface
    {
        $this->lastScopes = $scopes;
        $this->lastTimeoutSeconds = $timeoutSeconds;

        return $this->client;
    }
}
