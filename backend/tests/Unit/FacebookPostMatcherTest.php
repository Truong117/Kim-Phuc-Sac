<?php

namespace Tests\Unit;

use App\Services\Social\FacebookPostMatcher;
use App\Services\Social\FacebookReference;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookPostMatcherTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['cache.default' => 'array']);
        Cache::flush();
        Http::preventStrayRequests();
    }

    private function post(int $id): array
    {
        // API object ownership and permalink page prefix need not have the same numeric prefix.
        return ['id' => '100_'.$id, 'permalink_url' => 'https://www.facebook.com/987/posts/'.$id];
    }

    public function test_opaque_identity_matches_across_vanity_numeric_owner_and_story_url_shapes(): void
    {
        $paths = ['https://facebook.com/official/posts/pfbidExample', 'https://facebook.com/987/posts/pfbidExample', 'https://m.facebook.com/permalink.php?story_fbid=pfbidExample&id=987'];
        foreach ($paths as $path) {
            $this->assertContains('pfbid:pfbidExample', FacebookReference::keys($path));
        }
        $this->assertNotContains('pfbid:pfbidOther', FacebookReference::keys($paths[0]));
    }

    public function test_owned_numeric_permalink_redirects_to_opaque_identity_and_reuses_cache(): void
    {
        Http::fake(['https://www.facebook.com/987/posts/555' => Http::response('', 302, ['Location' => 'https://www.facebook.com/official/posts/pfbidExample/'])]);
        $budget = 1;
        $references = [FacebookReference::keys('https://facebook.com/another-name/posts/pfbidExample')];
        $prepared = app(FacebookPostMatcher::class)->prepare([$this->post(555)], $references, $budget, microtime(true) + 10);
        $this->assertContains('pfbid:pfbidExample', $prepared['posts'][0]['_reference_keys']);
        $this->assertSame(0, $budget);
        $cached = app(FacebookPostMatcher::class)->prepare([$this->post(555)], $references, $budget, microtime(true) + 10);
        $this->assertContains('pfbid:pfbidExample', $cached['posts'][0]['_reference_keys']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => $request->hasHeader('Authorization') || $request->hasHeader('Cookie'));
    }

    public function test_metadata_can_bridge_a_numeric_canonical_and_an_opaque_og_url(): void
    {
        Http::fake(['https://www.facebook.com/987/posts/555' => Http::response('<link rel="canonical" href="https://facebook.com/987/posts/555"><meta property="og:url" content="https://facebook.com/official/posts/pfbidExample">')]);
        $budget = 1;
        $prepared = app(FacebookPostMatcher::class)->prepare([$this->post(555)], [FacebookReference::keys('https://facebook.com/official/posts/pfbidExample')], $budget, microtime(true) + 10);
        $this->assertContains('object:555', $prepared['posts'][0]['_reference_keys']);
        $this->assertContains('pfbid:pfbidExample', $prepared['posts'][0]['_reference_keys']);
    }

    public function test_repeated_bounded_checks_continue_past_cached_posts_without_guessing(): void
    {
        Http::fake(fn ($request) => Http::response('', 302, ['Location' => 'https://facebook.com/official/posts/pfbid'.basename(parse_url($request->url(), PHP_URL_PATH))]));
        $posts = array_map(fn ($id) => $this->post($id), [1, 2, 3, 4]);
        $references = [FacebookReference::keys('https://facebook.com/official/posts/pfbid4')];
        for ($round = 1; $round <= 4; $round++) {
            $budget = 1;
            $prepared = app(FacebookPostMatcher::class)->prepare($posts, $references, $budget, microtime(true) + 10);
            $this->assertSame(4 - $round, $prepared['pending']);
        }
        $this->assertContains('pfbid:pfbid4', $prepared['posts'][3]['_reference_keys']);
        Http::assertSentCount(4);
    }

    public function test_external_redirects_do_not_create_identity_aliases_and_plain_ids_need_no_web_requests(): void
    {
        $budget = 1;
        app(FacebookPostMatcher::class)->prepare([$this->post(555)], [FacebookReference::keys('https://facebook.com/reel/555')], $budget, microtime(true) + 10);
        Http::assertNothingSent();
        Http::fake(['https://www.facebook.com/987/posts/555' => Http::response('', 302, ['Location' => 'https://outside.example/posts/pfbidExample'])]);
        $prepared = app(FacebookPostMatcher::class)->prepare([$this->post(555)], [FacebookReference::keys('https://facebook.com/official/posts/pfbidExample')], $budget, microtime(true) + 10);
        $this->assertNotContains('pfbid:pfbidExample', $prepared['posts'][0]['_reference_keys']);
        $this->assertSame(1, $prepared['unverified']);
        Http::assertSentCount(1);
    }
}
