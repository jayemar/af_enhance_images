<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Af_Enhance_Content;

/**
 * Test suite for Kagi News (kite.kagi.com) site-specific handling.
 *
 * Tests verify that the plugin correctly:
 * 1. Recognizes kite.kagi.com URLs
 * 2. Parses the "Sources:" link list out of Kagi's own content
 * 3. Leaves unrelated content/URLs untouched
 */
class Af_Enhance_Content_Kagi_Test extends TestCase {

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
    // is_kagi_news_url() TESTS
    // =====================================================================

    public function test_is_kagi_news_url_matches_kite_kagi_com() {
        $result = $this->callPrivateMethod('is_kagi_news_url', ['https://kite.kagi.com/abc123/world/1']);

        $this->assertTrue($result);
    }

    public function test_is_kagi_news_url_rejects_other_hosts() {
        $result = $this->callPrivateMethod('is_kagi_news_url', ['https://news.kagi.com/world.xml']);

        $this->assertFalse($result,
            'news.kagi.com (the feed XML host) is a different host from kite.kagi.com (the article page host)');
    }

    public function test_is_kagi_news_url_rejects_lookalike_domain() {
        $result = $this->callPrivateMethod('is_kagi_news_url', ['https://kite.kagi.com.evil.example/x']);

        $this->assertFalse($result);
    }

    // =====================================================================
    // extract_kagi_source_urls() TESTS
    // =====================================================================

    public function test_extract_kagi_source_urls_parses_sources_list() {
        $content = "<p>Some AI-generated summary.</p><h3>Highlights:</h3><ul><li>Fact one.</li></ul>" .
            "<h3>Sources:</h3><ul>" .
            "<li><a href='https://example.com/a'>Title A</a> - example.com</li>" .
            "<li><a href='https://other.com/b'>Title B</a> - other.com</li>" .
            "</ul>";

        $result = $this->callPrivateMethod('extract_kagi_source_urls', [$content]);

        $this->assertEquals(['https://example.com/a', 'https://other.com/b'], $result);
    }

    public function test_extract_kagi_source_urls_ignores_perspectives_links() {
        // Perspectives links must not leak into the Sources result - they're
        // a different, shorter, less reliably-present section.
        $content = "<h3>Perspectives:</h3><ul><li>Jane Doe: quote (<a href='https://perspectives.example/x'>Outlet</a>)</li></ul>" .
            "<h3>Sources:</h3><ul><li><a href='https://sources.example/y'>Title</a> - sources.example</li></ul>";

        $result = $this->callPrivateMethod('extract_kagi_source_urls', [$content]);

        $this->assertEquals(['https://sources.example/y'], $result);
    }

    public function test_extract_kagi_source_urls_returns_empty_without_sources_heading() {
        $content = "<p>Just a plain article with no Sources section.</p>";

        $result = $this->callPrivateMethod('extract_kagi_source_urls', [$content]);

        $this->assertEquals([], $result);
    }

    public function test_extract_kagi_source_urls_returns_empty_for_empty_content() {
        $result = $this->callPrivateMethod('extract_kagi_source_urls', ['']);

        $this->assertEquals([], $result);
    }

    // =====================================================================
    // get_kagi_source_skip_domains() / get_kagi_source_fetch_limit() TESTS
    // =====================================================================

    public function test_get_kagi_source_skip_domains_returns_default_when_unset() {
        $this->mockHost->method('get')->willReturnCallback(fn($plugin, $key, $default) => $default);

        $result = $this->callPrivateMethod('get_kagi_source_skip_domains');

        $this->assertEquals(['reddit.com', 'news.google.com', 'fark.com'], $result);
    }

    public function test_get_kagi_source_skip_domains_returns_saved_value() {
        $this->mockHost->method('get')->willReturnCallback(function($plugin, $key, $default) {
            if ($key === 'kagi_source_skip_domains') return json_encode(['example.com', 'other.example']);
            return $default;
        });

        $result = $this->callPrivateMethod('get_kagi_source_skip_domains');

        $this->assertEquals(['example.com', 'other.example'], $result);
    }

    public function test_get_kagi_source_skip_domains_respects_intentionally_empty_list() {
        // A saved empty JSON array means the user deliberately cleared the
        // list - it must NOT fall back to the built-in default.
        $this->mockHost->method('get')->willReturnCallback(function($plugin, $key, $default) {
            if ($key === 'kagi_source_skip_domains') return json_encode([]);
            return $default;
        });

        $result = $this->callPrivateMethod('get_kagi_source_skip_domains');

        $this->assertEquals([], $result);
    }

    public function test_get_kagi_source_fetch_limit_returns_default_when_unset() {
        $this->mockHost->method('get')->willReturnCallback(fn($plugin, $key, $default) => $default);

        $result = $this->callPrivateMethod('get_kagi_source_fetch_limit');

        $this->assertEquals(3, $result);
    }

    public function test_get_kagi_source_fetch_limit_returns_saved_value() {
        $this->mockHost->method('get')->willReturnCallback(function($plugin, $key, $default) {
            if ($key === 'kagi_source_fetch_limit') return 7;
            return $default;
        });

        $result = $this->callPrivateMethod('get_kagi_source_fetch_limit');

        $this->assertEquals(7, $result);
    }

    // =====================================================================
    // filter_kagi_skip_domains() TESTS
    // =====================================================================

    public function test_filter_kagi_skip_domains_removes_matching_hosts() {
        $urls = [
            'https://phys.org/article-1',
            'https://www.reddit.com/r/climate/comments/xyz/',
            'https://news.google.com/rss/articles/abc',
            'https://newsday.com/article-2',
        ];

        $result = $this->callPrivateMethod('filter_kagi_skip_domains',
            [$urls, ['reddit.com', 'news.google.com', 'fark.com']]);

        $this->assertEquals([
            'https://phys.org/article-1',
            'https://newsday.com/article-2',
        ], $result);
    }

    public function test_filter_kagi_skip_domains_matches_subdomains() {
        $urls = ['https://old.reddit.com/r/climate/comments/xyz/', 'https://phys.org/article-1'];

        $result = $this->callPrivateMethod('filter_kagi_skip_domains', [$urls, ['reddit.com']]);

        $this->assertEquals(['https://phys.org/article-1'], $result);
    }

    public function test_filter_kagi_skip_domains_keeps_non_matching_hosts() {
        $urls = ['https://phys.org/article-1', 'https://newsday.com/article-2'];

        $result = $this->callPrivateMethod('filter_kagi_skip_domains', [$urls, ['reddit.com']]);

        $this->assertEquals($urls, $result);
    }

    public function test_filter_kagi_skip_domains_with_empty_skip_list_keeps_all() {
        $urls = ['https://phys.org/article-1', 'https://www.reddit.com/r/climate/x'];

        $result = $this->callPrivateMethod('filter_kagi_skip_domains', [$urls, []]);

        $this->assertEquals($urls, $result);
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
