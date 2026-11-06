<?php

namespace App\Services\Social;

use DOMDocument;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

// Resolve public Facebook aliases only. No Page token, session cookie or login flow is used here.
class FacebookShareResolver
{
    public function resolve(string $value, int &$budget, float $deadline): array
    {
        $url = FacebookReference::url($value);
        if ($url === null) {
            return ['status' => 'unverified', 'url' => null];
        }
        if (! FacebookReference::isShare($url)) {
            return ['status' => FacebookReference::keys($url) !== [] ? 'resolved' : 'unverified', 'url' => $url];
        }

        return $this->fetch($url, $budget, $deadline, false);
    }

    // Only callers holding a fresh Page API catalogue may use this for its permalink URLs.
    public function ownedPostAliases(string $value, int &$budget, float $deadline): array
    {
        $url = FacebookReference::url($value);
        if ($url === null || FacebookReference::keys($url) === []) {
            return ['status' => 'unverified', 'url' => null, 'aliases' => []];
        }

        return $this->fetch($url, $budget, $deadline, true);
    }

    private function fetch(string $url, int &$budget, float $deadline, bool $ownedPost): array
    {
        $key = ($ownedPost ? 'facebook-post-alias:v3:' : 'facebook-share:v1:').hash('sha256', $url);
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        if ($budget <= 0 || microtime(true) >= $deadline) {
            return ['status' => 'pending', 'url' => null];
        }
        $lock = Cache::lock($key.':lock', 10);
        if (! $lock->get()) {
            return ['status' => 'pending', 'url' => null];
        }
        $budget--;
        $result = ['status' => 'unverified', 'url' => null, 'reason' => 'redirect_limit'];
        try {
            $current = $url;
            $aliases = [];
            $visited = [];
            for ($hop = 0; $hop < 3 && microtime(true) < $deadline; $hop++) {
                $current = preg_replace('~^https://facebook\.com/~', 'https://www.facebook.com/', $current);
                if (isset($visited[$current])) {
                    $result['reason'] = 'redirect_loop';
                    break;
                }
                $visited[$current] = true;
                // Each request is fresh: redirects and cookies are never followed automatically.
                $response = Http::withHeaders(['Accept' => 'text/html', 'User-Agent' => 'KPS-Link-Check/1.0'])
                    ->connectTimeout(1)->timeout(min(2, max(0.1, $deadline - microtime(true))))
                    ->withOptions(['allow_redirects' => false, 'cookies' => false, 'stream' => true, 'read_timeout' => 2])->get($current);
                if ($response->redirect()) {
                    $location = $response->header('Location');
                    $result['reason'] = preg_match('~(?:^|/)(?:login|checkpoint|consent)(?:[/.?]|$)~i', $location) ? 'login_required' : 'redirect_unavailable';
                    $target = $this->redirectTarget($current, $location);
                } elseif ($response->successful()) {
                    $stream = $response->toPsrResponse()->getBody();
                    $body = '';
                    while (! $stream->eof() && strlen($body) <= 1048576 && microtime(true) < $deadline) {
                        $chunk = $stream->read(min(8192, 1048577 - strlen($body)));
                        if ($chunk === '') {
                            break;
                        }
                        $body .= $chunk;
                    }
                    $complete = $stream->eof() && strlen($body) <= 1048576;
                    $result['reason'] = $complete ? 'metadata_unavailable' : 'response_limit';
                    $metadata = $complete ? $this->metadataUrls($body) : [];
                    $target = $metadata[0] ?? null;
                    if ($ownedPost && $target !== null) {
                        $result = ['status' => 'resolved', 'url' => $target, 'aliases' => array_values(array_unique([...$aliases, ...$metadata]))];
                        break;
                    }
                } else {
                    $result['reason'] = match ($response->status()) {
                        429 => 'rate_limited',
                        401, 403 => 'access_required',
                        default => 'http_'.$response->status(),
                    };
                    break;
                }
                if ($target === null) {
                    break;
                }
                if ($ownedPost && ! FacebookReference::isShare($target)) {
                    $aliases[] = $target;
                    if (count(array_filter(FacebookReference::keys($target), fn ($key) => str_starts_with($key, 'pfbid:'))) > 0) {
                        $result = ['status' => 'resolved', 'url' => $target, 'aliases' => array_values(array_unique($aliases))];
                        break;
                    }
                }
                if (! $ownedPost && ! FacebookReference::isShare($target)) {
                    $result = ['status' => 'resolved', 'url' => $target];
                    break;
                }
                $current = $target;
            }
        } catch (Throwable) {
            $result['reason'] = 'transport_unavailable';
            // Provider errors are advisory and must never leak HTML, credentials or raw exceptions.
        }
        try {
            Cache::put($key, $result, $result['status'] === 'resolved' ? 86400 : 60);
        } finally {
            $lock->release();
        }

        return $result;
    }

    private function redirectTarget(string $current, string $location): ?string
    {
        $parts = parse_url($location);
        // Validate before URI resolution, which can normalize an explicit default port away.
        if (! is_array($parts) || isset($parts['port']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $this->target((string) UriResolver::resolve(new Uri($current), new Uri($location)));
    }

    private function target(string $value): ?string
    {
        $url = FacebookReference::url($value);

        return $url !== null && (FacebookReference::isShare($url) || FacebookReference::keys($url) !== []) ? $url : null;
    }

    private function metadataUrls(string $html): array
    {
        $urls = [];
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            foreach ($document->getElementsByTagName('link') as $node) {
                if (strtolower($node->getAttribute('rel')) === 'canonical' && ($url = $this->target($node->getAttribute('href'))) !== null && ! FacebookReference::isShare($url)) {
                    $urls[] = $url;
                }
            }
            foreach ($document->getElementsByTagName('meta') as $node) {
                if (strtolower($node->getAttribute('property')) === 'og:url' && ($url = $this->target($node->getAttribute('content'))) !== null && ! FacebookReference::isShare($url)) {
                    $urls[] = $url;
                }
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return array_values(array_unique($urls));
    }
}
