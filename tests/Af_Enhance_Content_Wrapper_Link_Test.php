<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Af_Enhance_Content;

/**
 * Test suite for wrapper/aggregator feed site-link handling.
 *
 * Tests verify that the plugin correctly:
 * 1. Detects wrapper feeds generically (feed site_url domain differs from
 *    the article's own link domain) - not tied to any one named site
 * 2. Only adds a visible link when a site-specific browse-URL is known
 * 3. Leaves normal (non-wrapper) feeds untouched
 */
class Af_Enhance_Content_Wrapper_Link_Test extends TestCase {

    private $plugin;
    private $mockHost;

    protected function setUp(): void {
        $this->mockHost = $this->createMock(\PluginHost::class);
        $this->mockHost->expects($this->any())
            ->method('add_hook')
            ->willReturn(true);

        if (!class_exists('Debug')) {
            eval('class Debug {
                const LOG_VERBOSE = 1;
                static function log($msg, $level = 0) {}
            }');
        }

        $this->plugin = new Af_Enhance_Content();
        $this->plugin->init($this->mockHost);
    }

    // =====================================================================
    // normalize_host() TESTS
    // =====================================================================

    public function test_normalize_host_strips_www() {
        $result = $this->callPrivateMethod('normalize_host', ['https://www.example.com/path']);

        $this->assertEquals('example.com', $result);
    }

    public function test_normalize_host_lowercases() {
        $result = $this->callPrivateMethod('normalize_host', ['https://EXAMPLE.com/path']);

        $this->assertEquals('example.com', $result);
    }

    public function test_normalize_host_returns_null_for_invalid_url() {
        $result = $this->callPrivateMethod('normalize_host', ['not a url']);

        $this->assertNull($result);
    }

    // =====================================================================
    // wrapper_site_browse_url() TESTS
    // =====================================================================

    public function test_wrapper_site_browse_url_for_hacker_news() {
        $result = $this->callPrivateMethod('wrapper_site_browse_url', ['news.ycombinator.com', 'example.com']);

        $this->assertEquals('https://news.ycombinator.com/from?site=example.com', $result);
    }

    public function test_wrapper_site_browse_url_returns_null_for_unknown_wrapper() {
        $result = $this->callPrivateMethod('wrapper_site_browse_url', ['lobste.rs', 'example.com']);

        $this->assertNull($result,
            'Only wrapper sites with a known browse-URL scheme should produce a link - detection alone is not enough');
    }

    // =====================================================================
    // add_wrapper_site_link() TESTS
    // =====================================================================

    public function test_add_wrapper_site_link_for_hacker_news_article() {
        $this->mockHost->expects($this->any())->method('get')->willReturnCallback(
            fn($plugin, $key, $default) => $default
        );

        $article = [
            'title' => 'Show HN: Something',
            'content' => '<p>Comments</p>',
            'link' => 'https://superdario.pawb.de',
            'feed' => ['site_url' => 'https://news.ycombinator.com/'],
        ];

        $result = $this->callPrivateMethod('add_wrapper_site_link', [$article]);

        $this->assertStringContainsString('https://news.ycombinator.com/from?site=superdario.pawb.de', $result['content']);
        $this->assertStringContainsString('superdario.pawb.de', $result['content']);
        $this->assertStringContainsString('<p>Comments</p>', $result['content'],
            'Should prepend, not replace, the existing content');
    }

    public function test_add_wrapper_site_link_skips_when_domains_match() {
        // Not a wrapper situation: the feed's own site IS the article's site
        // (e.g. a normal blog feed), so nothing should be added.
        $article = [
            'title' => 'A normal blog post',
            'content' => '<p>Real content here.</p>',
            'link' => 'https://example.com/posts/1',
            'feed' => ['site_url' => 'https://example.com/'],
        ];

        $result = $this->callPrivateMethod('add_wrapper_site_link', [$article]);

        $this->assertEquals('<p>Real content here.</p>', $result['content']);
    }

    public function test_add_wrapper_site_link_skips_unknown_wrapper() {
        // A genuine wrapper situation (feed site != article site), but not
        // one we know a browse-URL scheme for - should be left untouched.
        $article = [
            'title' => 'Some Lobsters link',
            'content' => '<p>Comments</p>',
            'link' => 'https://example.com/post',
            'feed' => ['site_url' => 'https://lobste.rs/'],
        ];

        $result = $this->callPrivateMethod('add_wrapper_site_link', [$article]);

        $this->assertEquals('<p>Comments</p>', $result['content']);
    }

    public function test_add_wrapper_site_link_handles_missing_feed_data() {
        $article = [
            'title' => 'Test',
            'content' => '<p>Content</p>',
            'link' => 'https://example.com/post',
        ];

        $result = $this->callPrivateMethod('add_wrapper_site_link', [$article]);

        $this->assertEquals('<p>Content</p>', $result['content']);
    }

    // =====================================================================
    // HELPER METHODS
    // =====================================================================

    private function callPrivateMethod($methodName, array $args = []) {
        $reflection = new \ReflectionClass($this->plugin);
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs($this->plugin, $args);
    }
}
