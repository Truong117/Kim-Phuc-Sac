<?php

namespace App\Services\WeeklyPlanning\GoogleDocs;

use App\Services\WeeklyPlanning\GoogleDocs\Contracts\GoogleAuthorizedHttpClientFactory;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\TransferException;
use JsonException;
use Psr\Http\Message\StreamInterface;
use Throwable;

final class BoundedGoogleJsonTransport
{
    public function __construct(private readonly GoogleAuthorizedHttpClientFactory $clients) {}

    /**
     * @param  list<string>  $scopes
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    public function get(
        string $fixedEndpoint,
        array $scopes,
        array $query,
        int $timeoutSeconds,
        int $maxResponseBytes,
    ): array {
        $timeoutSeconds = max(1, $timeoutSeconds);
        $maxResponseBytes = max(1, $maxResponseBytes);

        try {
            $client = $this->clients->create($scopes, $timeoutSeconds);
            $response = $client->request('GET', $fixedEndpoint, [
                'query' => $query,
                'connect_timeout' => min($timeoutSeconds, 10),
                'timeout' => $timeoutSeconds,
                'http_errors' => false,
                'allow_redirects' => false,
                'verify' => true,
                'stream' => true,
                'headers' => ['Accept' => 'application/json'],
            ]);
        } catch (GoogleServiceAccountConfigurationException) {
            throw new GoogleApiTransportException(0, 'CONFIGURATION');
        } catch (ConnectException) {
            throw new GoogleApiTransportException(0, 'TIMEOUT');
        } catch (TransferException) {
            throw new GoogleApiTransportException(0, 'TRANSPORT');
        } catch (Throwable) {
            throw new GoogleApiTransportException(0, 'TRANSPORT');
        }

        $status = $response->getStatusCode();
        $body = $response->getBody();
        if ($status < 200 || $status >= 300) {
            $body->close();
            throw new GoogleApiTransportException($status);
        }

        $declaredLength = $response->getHeaderLine('Content-Length');
        if ($declaredLength !== ''
            && ctype_digit($declaredLength)
            && (int) $declaredLength > $maxResponseBytes) {
            $body->close();
            throw new GoogleApiTransportException(0, 'RESPONSE_TOO_LARGE');
        }

        try {
            $json = $this->readBounded($body, $maxResponseBytes);
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (GoogleApiTransportException $exception) {
            throw $exception;
        } catch (JsonException) {
            throw new GoogleApiTransportException(0, 'MALFORMED_RESPONSE');
        } finally {
            $body->close();
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new GoogleApiTransportException(0, 'MALFORMED_RESPONSE');
        }

        return $decoded;
    }

    private function readBounded(StreamInterface $body, int $maxResponseBytes): string
    {
        $contents = '';
        $bytes = 0;
        while (! $body->eof()) {
            $chunk = $body->read(min(8192, ($maxResponseBytes - $bytes) + 1));
            if ($chunk === '' && ! $body->eof()) {
                throw new GoogleApiTransportException(0, 'TRANSPORT');
            }
            $bytes += strlen($chunk);
            if ($bytes > $maxResponseBytes) {
                throw new GoogleApiTransportException(0, 'RESPONSE_TOO_LARGE');
            }
            $contents .= $chunk;
        }

        return $contents;
    }
}
