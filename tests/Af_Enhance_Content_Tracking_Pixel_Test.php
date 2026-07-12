<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Af_Enhance_Content;

/**
 * Test suite for Af_Enhance_Content plugin - Tracking Pixel Removal
 *
 * Tests verify that the plugin correctly:
 * 1. Strips <img> tags declared 2px or smaller in both width and height
 * 2. Strips <img> tags whose URL matches a known tracking/beacon pattern,
 *    even with no declared dimensions (e.g. NPR's own RSS beacon)
 * 3. Preserves images that match neither signal
 * 4. Respects the strip_tracking_pixels setting toggle
 */
class Af_Enhance_Content_Tracking_Pixel_Test extends TestCase {

    private $plugin;
    private $mockHost;

    protected function setUp(): void {
        // Mock the PluginHost
        $this->mockHost = $this->createMock(\PluginHost::class);

        // Suppress the host->add_hook call during init
        $this->mockHost->expects($this->any())
            ->method('add_hook')
            ->willReturn(true);

        // Mock host->get to return defaults with strip_tracking_pixels enabled
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'inline_enhancement') return true;
                if ($key === 'strip_tracking_pixels') return true;
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'fetch_mode') return 'never';
                if ($key === 'extract_og') return false;
                if ($key === 'upgrade_enclosures') return false;
                return $default;
            });

        // Mock Debug class if not available
        if (!class_exists('Debug')) {
            eval('class Debug {
                const LOG_VERBOSE = 1;
                static function log($msg, $level = 0) {}
            }');
        }

        // Create plugin instance
        $this->plugin = new Af_Enhance_Content();
        $this->plugin->init($this->mockHost);
    }

    /**
     * Test 1: Classic 1x1 tracking pixel is stripped
     */
    public function test_strips_1x1_pixel() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="https://example.com/beacon.gif" width="1" height="1">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringNotContainsString('<img', $result['content'],
            'Should strip a 1x1 tracking pixel entirely');
    }

    /**
     * Test 2: 2x2 boundary is stripped
     */
    public function test_strips_2x2_pixel() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="https://example.com/beacon.gif" width="2" height="2">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringNotContainsString('<img', $result['content'],
            'Should strip a 2x2 pixel (boundary is inclusive at 2px)');
    }

    /**
     * Test 3: 3x3 is preserved (boundary is exclusive above 2px)
     */
    public function test_preserves_3x3_image() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="https://example.com/foo.jpg" width="3" height="3">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('<img', $result['content'],
            'Should preserve a 3x3 image (just outside the tiny-pixel range)');
    }

    /**
     * Test 4: Normal content image is preserved
     */
    public function test_preserves_normal_content_image() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="https://example.com/photos/sunset.jpg" width="800" height="600" alt="Sunset">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="https://example.com/photos/sunset.jpg"', $result['content'],
            'Should preserve a normal-sized content image');
    }

    /**
     * Test 5: width=1 with no height attribute is NOT stripped
     */
    public function test_width_only_no_height_is_not_stripped() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="https://example.com/foo.jpg" width="1">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('<img', $result['content'],
            'Should not strip when only width is present without height');
    }

    /**
     * Test 6: Image with no dimensions AND a URL matching no known pattern is
     * preserved (documents the remaining gap: an undeclared-size beacon whose
     * URL also doesn't match the tracking/pixel pattern can't be detected).
     */
    public function test_no_dimensions_and_no_url_match_is_not_stripped() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="https://example.com/analytics/log.gif" alt="">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('<img', $result['content'],
            'Cannot detect a beacon with no declared dimensions and no matching URL pattern');
    }

    /**
     * Test 9: NPR's real-world RSS tracking pixel (no declared dimensions,
     * "/tracking/" in the URL path) is stripped via the URL-pattern signal.
     */
    public function test_strips_npr_style_tracking_pixel_by_url() {
        $article = [
            'title' => 'Test Article',
            'content' => "<img src='https://media.npr.org/include/images/tracking/npr-rss-pixel.png?story=nx-s1-5876380' />"
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringNotContainsString('<img', $result['content'],
            'Should strip a tracking-pixel URL even with no declared width/height');
    }

    /**
     * Test 10: A "-pixel." filename pattern with no dimensions is stripped.
     */
    public function test_strips_dash_pixel_filename_pattern() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="https://example.com/assets/open-pixel.gif">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringNotContainsString('<img', $result['content'],
            'Should strip a "-pixel." filename pattern even with no declared dimensions');
    }

    /**
     * Test 11: URL-pattern check also inspects data-src (lazy-loaded beacons).
     */
    public function test_strips_tracking_url_in_data_src() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img data-src="https://example.com/tracking/beacon.gif">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringNotContainsString('beacon.gif', $result['content'],
            'Should strip a tracking-pixel URL found in data-src');
    }

    /**
     * Test 12: A normal content image URL is not affected by the URL-pattern check.
     */
    public function test_url_pattern_check_does_not_affect_normal_images() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="https://example.com/photos/sunset.jpg" alt="Sunset">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="https://example.com/photos/sunset.jpg"', $result['content'],
            'A normal image URL should not match the tracking-pixel URL pattern');
    }

    /**
     * Test 7: Feature disabled preserves a classic 1x1 tracking pixel
     */
    public function test_disabled_setting_preserves_tracking_pixel() {
        $mockHost = $this->createMock(\PluginHost::class);
        $mockHost->expects($this->any())
            ->method('add_hook')
            ->willReturn(true);
        $mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'inline_enhancement') return true;
                if ($key === 'strip_tracking_pixels') return false;
                if ($key === 'fetch_mode') return 'never';
                if ($key === 'extract_og') return false;
                if ($key === 'upgrade_enclosures') return false;
                return $default;
            });

        $plugin = new Af_Enhance_Content();
        $plugin->init($mockHost);

        $article = [
            'title' => 'Test Article',
            'content' => '<img src="https://example.com/beacon.gif" width="1" height="1">'
        ];

        $result = $plugin->hook_article_filter($article);

        $this->assertStringContainsString('<img', $result['content'],
            'Should preserve the tracking pixel when the setting is disabled');
    }

    /**
     * Test 7b: Feature disabled also preserves a URL-pattern-matched pixel
     * (no declared dimensions), confirming both signals share the same gate.
     */
    public function test_disabled_setting_preserves_url_pattern_pixel() {
        $mockHost = $this->createMock(\PluginHost::class);
        $mockHost->expects($this->any())
            ->method('add_hook')
            ->willReturn(true);
        $mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'inline_enhancement') return true;
                if ($key === 'strip_tracking_pixels') return false;
                if ($key === 'fetch_mode') return 'never';
                if ($key === 'extract_og') return false;
                if ($key === 'upgrade_enclosures') return false;
                return $default;
            });

        $plugin = new Af_Enhance_Content();
        $plugin->init($mockHost);

        $article = [
            'title' => 'Test Article',
            'content' => '<img src="https://example.com/tracking/beacon.gif">'
        ];

        $result = $plugin->hook_article_filter($article);

        $this->assertStringContainsString('<img', $result['content'],
            'Should preserve a tracking-URL pixel when the setting is disabled');
    }

    /**
     * Test 8: Multiple images - only the tiny one is stripped
     */
    public function test_strips_only_the_tiny_image_among_multiple() {
        $article = [
            'title' => 'Test Article',
            'content' => '<p>Text</p>
                <img src="https://example.com/photos/one.jpg" width="800" height="600">
                <img src="https://example.com/beacon.gif" width="1" height="1">
                <p>More text</p>'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="https://example.com/photos/one.jpg"', $result['content'],
            'Should preserve the normal content image');
        $this->assertStringNotContainsString('beacon.gif', $result['content'],
            'Should strip the tracking pixel');
    }
}
