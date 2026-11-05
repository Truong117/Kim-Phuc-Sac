<?php

namespace Tests\Unit;

use App\Services\Social\FacebookReference;
use App\Services\Social\FacebookShareResolver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookShareResolverTest extends TestCase
{
    private function resolve(string $url): array
    {
        $budget = 3;

        return app(FacebookShareResolver::class)->resolve($url, $budget, microtime(true) + 10);
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
        Http::preventStrayRequests();
    }

    public function test_share_redirect_and_metadata_are_resolved_without_credentials_and_cached(): void
    {
        Http::fake([
            'https://www.facebook.com/share/r/Example' => Http::response('', 302, ['Location' => '/share/v/Next']),
            'https://www.facebook.com/share/v/Next' => Http::response('<meta property="og:url" content="https://www.facebook.com/reel/1129745132867197/?rdid=tracking">'),
        ]);
        $expected = ['status' => 'resolved', 'url' => 'https://facebook.com/reel/1129745132867197'];
        $this->assertSame($expected, $this->resolve('https://www.facebook.com/share/r/Example/'));
        $this->assertSame($expected, $this->resolve('https://m.facebook.com/share/r/Example/'));
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization') || $request->hasHeader('Cookie') || str_contains($request->url(), 'access_token'));
    }

    public function test_external_login_and_ambiguous_targets_are_not_followed_and_failures_are_cached(): void
    {
        $targets = ['https://127.0.0.1/reel/555', 'https://facebook.com.evil.test/reel/555', 'https://user:pass@facebook.com/reel/555', 'http://facebook.com/reel/555', 'https://facebook.com:443/reel/555', '/login/?next=/reel/555', '/photo.php', 'javascript:alert(1)'];
        Http::fake(function ($request) use ($targets) {
            $index = (int) substr(parse_url($request->url(), PHP_URL_PATH), strlen('/share/r/Test'));

            return Http::response('', 302, ['Location' => $targets[$index]]);
        });
        foreach ($targets as $index => $target) {
            $this->assertSame('unverified', $this->resolve('https://www.facebook.com/share/r/Test'.$index)['status']);
            $this->assertSame('unverified', $this->resolve('https://www.facebook.com/share/r/Test'.$index)['status']);
        }
        Http::assertSentCount(8);
    }

    public function test_budgets_redirect_loops_large_bodies_and_unrelated_ids_do_not_match(): void
    {
        Http::fake([
            'https://www.facebook.com/share/r/Loop' => Http::response('', 302, ['Location' => '/share/r/Loop']),
            'https://www.facebook.com/share/p/Large' => Http::response(str_repeat('x', 1048577).'<link rel="canonical" href="https://facebook.com/reel/555">'),
            'https://www.facebook.com/share/v/Unknown' => Http::response('<script>var id = "555";</script>'),
        ]);
        foreach (['r/Loop', 'p/Large', 'v/Unknown'] as $path) {
            $this->assertSame('unverified', $this->resolve('https://www.facebook.com/share/'.$path)['status']);
        }
        $budget = 0;
        $this->assertSame('pending', app(FacebookShareResolver::class)->resolve('https://www.facebook.com/share/r/Pending', $budget, microtime(true) + 1)['status']);
        Http::assertSentCount(3);
        $this->assertSame([], FacebookReference::keys('https://facebook.com/login'));
        $this->assertSame([], FacebookReference::keys('https://facebook.com/photo.php'));
        $this->assertNotSame(FacebookReference::keys('https://facebook.com/photo.php?fbid=111'), FacebookReference::keys('https://facebook.com/photo.php?fbid=222'));
    }

    public function test_plain_post_urls_never_need_a_public_web_request(): void
    {
        $this->assertSame('resolved', $this->resolve('https://facebook.com/kps/posts/555')['status']);
        $this->assertSame('unverified', $this->resolve('https://facebook.com/kps')['status']);
        $this->assertSame('unverified', $this->resolve('https://example.test/share/r/Example')['status']);
        Http::assertNothingSent();
    }
}
