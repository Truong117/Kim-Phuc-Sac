<?php

namespace Tests\Feature;

use App\Services\Social\FacebookApiException;
use App\Services\Social\FacebookPostSampler;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FacebookPostSamplerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['social_metrics.enabled' => true, 'social_metrics.graph_version' => 'v27.0', 'social_metrics.views_metric' => null]);
        Http::preventStrayRequests();
    }

    public static function fatalErrors(): array
    {
        return [
            'expired token' => [400, ['code' => 190], 'SOCIAL_RECONNECT_REQUIRED'],
            'HTTP unauthorized' => [401, [], 'SOCIAL_RECONNECT_REQUIRED'],
            'Page permission' => [403, ['code' => 10], 'SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE'],
            'permission 200' => [400, ['code' => 200], 'SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE'],
            'unknown parameter' => [400, ['code' => 100], 'SOCIAL_PERMISSION_OR_OBJECT_UNAVAILABLE'],
            'rate limited' => [400, ['code' => 4], 'SOCIAL_RATE_LIMITED'],
            'HTTP rate limited' => [429, [], 'SOCIAL_RATE_LIMITED'],
            'provider outage' => [503, ['code' => 100, 'error_subcode' => 33], 'SOCIAL_PROVIDER_UNAVAILABLE'],
            'transient provider' => [400, ['code' => 100, 'error_subcode' => 33, 'is_transient' => true], 'SOCIAL_PROVIDER_UNAVAILABLE'],
        ];
    }

    #[DataProvider('fatalErrors')]
    public function test_global_and_unknown_errors_are_never_skipped(int $status, array $error, string $code): void
    {
        Http::fake(['*' => Http::response(['error' => $error], $status)]);
        try {
            app(FacebookPostSampler::class)->sample('private-test-token', '100', '100_1', ['entry_id' => 1]);
            $this->fail('Expected fatal provider error');
        } catch (FacebookApiException $exception) {
            $this->assertSame($code, $exception->getMessage());
        }
        Http::assertSentCount(1);
    }

    public function test_comment_failure_can_be_isolated_after_verifying_the_same_edge_on_another_post(): void
    {
        Log::spy();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($path === '/v27.0/100_1/comments') {
                return Http::response(['error' => ['code' => 100, 'error_subcode' => 33]], 400);
            }
            if (str_ends_with($path, '/published_posts')) {
                return Http::response(['data' => [['id' => '100_2']]]);
            }

            return Http::response(['summary' => ['total_count' => 2]]);
        });
        $this->assertNull(app(FacebookPostSampler::class)->sample('private-test-token', '100', '100_1', ['entry_id' => 7], $peerReadable));
        $this->assertTrue($peerReadable);
        Http::assertSent(fn ($r) => str_contains($r->url(), '100_2/comments'));
        Log::shouldHaveReceived('warning')->withArgs(fn ($event, $context) => $event === 'facebook_metrics.post_skipped'
            && $context['operation'] === 'comments' && $context['entry_id'] === 7)->once();
    }

    public function test_empty_catalogue_skips_one_cycle_without_confirming_an_isolated_failure(): void
    {
        Http::fake(fn ($request) => str_contains($request->url(), 'published_posts')
            ? Http::response(['data' => []])
            : Http::response(['error' => ['code' => 100, 'error_subcode' => 33]], 400));
        $peerReadable = true;
        $this->assertNull(app(FacebookPostSampler::class)->sample('fixture', '100', '100_1', [], $peerReadable));
        $this->assertFalse($peerReadable);
    }

    public function test_successful_http_responses_without_any_metric_values_cannot_mark_a_post_recovered(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);
        $this->assertNull(app(FacebookPostSampler::class)->sample('fixture', '100', '100_1', [], $peerReadable));
        $this->assertFalse($peerReadable);
        Http::assertSentCount(2);
    }

    public function test_an_unavailable_peer_can_be_verified_with_a_second_peer(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'published_posts')) {
                return Http::response(['data' => [['id' => '100_2'], ['id' => '100_3']]]);
            }

            return str_contains($request->url(), '100_3/reactions')
                ? Http::response(['summary' => ['total_count' => 0]])
                : Http::response(['error' => ['code' => 100, 'error_subcode' => 33]], 400);
        });
        $this->assertNull(app(FacebookPostSampler::class)->sample('fixture', '100', '100_1', [], $peerReadable));
        $this->assertTrue($peerReadable);
        Http::assertSentCount(4);
    }

    public function test_page_listing_alone_does_not_hide_global_metric_permission_failure(): void
    {
        Http::fake(function ($request) {
            return str_contains($request->url(), 'published_posts')
                ? Http::response(['data' => [['id' => '100_2']]])
                : Http::response(['error' => ['code' => 100, 'error_subcode' => 33]], 400);
        });
        $this->expectException(FacebookApiException::class);
        app(FacebookPostSampler::class)->sample('private-test-token', '100', '100_1', ['entry_id' => 1]);
    }

    public function test_malformed_probe_response_is_not_accepted_as_an_empty_page(): void
    {
        Http::fake(function ($request) {
            return str_contains($request->url(), 'published_posts') ? Http::response(['unexpected' => true])
                : Http::response(['error' => ['code' => 100, 'error_subcode' => 33]], 400);
        });
        $this->expectException(FacebookApiException::class);
        app(FacebookPostSampler::class)->sample('private-test-token', '100', '100_1', ['entry_id' => 1]);
    }

    public function test_diagnostics_redact_credentials_and_optional_views_do_not_discard_real_metrics(): void
    {
        config(['social_metrics.views_metric' => 'post_media_view', 'social_metrics.app_secret' => 'fixture-app-secret']);
        $token = 'fixture-token-never-log';
        $proof = hash_hmac('sha256', $token, 'fixture-app-secret');
        Log::spy();
        Http::fake(function ($request) use ($token, $proof) {
            return str_contains($request->url(), '/insights')
                ? Http::response(['error' => ['code' => 100, 'message' => "Bad $token fixture-app-secret $proof https://example.com/?access_token=$token\nprovider-message",
                    'fbtrace_id' => 'trace-optional']], 400)
                : Http::response(['summary' => ['total_count' => 5]]);
        });
        $metrics = app(FacebookPostSampler::class)->sample($token, '100', '100_1', ['entry_id' => 1]);
        $this->assertSame(['likes' => 5, 'comments' => 5, 'media_views' => null], $metrics);
        Log::shouldHaveReceived('warning')->withArgs(function ($event, $context) use ($token, $proof) {
            $json = json_encode($context);

            return $event === 'facebook_metrics.optional_metric_unavailable' && $context['operation'] === 'insights'
                && $context['provider_trace_id'] === 'trace-optional' && ! str_contains($json, $token)
                && ! str_contains($json, 'fixture-app-secret') && ! str_contains($json, $proof)
                && ! str_contains($context['provider_message'], "\n") && str_contains($json, 'provider-message');
        })->once();
        Log::shouldHaveReceived('warning')->once();
    }
}
