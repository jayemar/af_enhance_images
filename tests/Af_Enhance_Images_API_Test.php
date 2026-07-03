<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Af_Enhance_Images;

/**
 * Test suite for Af_Enhance_Images hook_render_article_api
 *
 * Contract note: HOOK_RENDER_ARTICLE_API receives a wrapper row
 * (['headline' => ...] from getHeadlines, ['article' => ...] from getArticle)
 * and must return the UNWRAPPED article - core's chain_hooks_callback assigns
 * the return value directly over the headline/article row (see API.php and the
 * stock nsfw plugin). Earlier versions of this suite asserted wrapped output,
 * which was never what core expected.
 *
 * The hook does display-time work on every API response:
 * 1. Guarantees content is a string (null content caused a production
 *    TypeError in DiskCache::rewrite_urls()).
 * 2. Strips tracking pixels from content - this retroactively cleans articles
 *    stored before import-time stripping existed, for every client.
 * 3. Clears flavor_image when it is itself a tracking-pixel URL.
 */
class Af_Enhance_Images_API_Test extends TestCase {

    private $plugin;
    private $mockHost;

    protected function setUp(): void {
        $this->mockHost = $this->createMock(\PluginHost::class);

        $this->mockHost->expects($this->any())
            ->method('add_hook')
            ->willReturn(true);

        // Return defaults for all settings (strip_tracking_pixels defaults on)
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                return $default;
            });

        if (!class_exists('Debug')) {
            eval('class Debug {
                const LOG_VERBOSE = 1;
                static function log($msg, $level = 0) {}
            }');
        }

        $this->plugin = new Af_Enhance_Images();
        $this->plugin->init($this->mockHost);
    }

    /** Build a plugin instance with strip_tracking_pixels disabled. */
    private function makePluginWithStripDisabled() {
        $mockHost = $this->createMock(\PluginHost::class);
        $mockHost->expects($this->any())
            ->method('add_hook')
            ->willReturn(true);
        $mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'strip_tracking_pixels') return false;
                return $default;
            });

        $plugin = new Af_Enhance_Images();
        $plugin->init($mockHost);
        return $plugin;
    }

    // =====================================================================
    // NULL-CONTENT GUARD (the original production TypeError)
    // =====================================================================

    public function test_null_content_in_headline_converted_to_empty_string() {
        $row = [
            'headline' => [
                'title' => 'Justified True Belief',
                'content' => null
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertIsString($result['content'],
            'Content must be string to prevent TypeError in DiskCache::rewrite_urls()');
        $this->assertSame('', $result['content'],
            'Null content should be converted to empty string');
    }

    public function test_null_content_in_article_converted_to_empty_string() {
        $row = [
            'article' => [
                'title' => 'Test Article',
                'content' => null
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertIsString($result['content'], 'Content must be string type');
        $this->assertSame('', $result['content'],
            'Null content should be converted to empty string');
    }

    public function test_missing_content_field_is_added() {
        $row = [
            'headline' => [
                'title' => 'Test Article'
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertArrayHasKey('content', $result, 'Content field must exist');
        $this->assertSame('', $result['content'],
            'Missing content should be added as empty string');
    }

    public function test_empty_string_content_is_preserved() {
        $row = [
            'headline' => [
                'title' => 'Test Article',
                'content' => ''
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertSame('', $result['content'],
            'Empty string content should be preserved as-is');
    }

    public function test_normal_content_unchanged() {
        $content = '<p>Normal article content</p>';
        $row = [
            'headline' => [
                'title' => 'Test Article',
                'content' => $content
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertSame($content, $result['content'],
            'Normal content should pass through unchanged');
    }

    public function test_string_zero_content_is_preserved() {
        $row = [
            'headline' => [
                'title' => 'Test Article',
                'content' => '0'
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertSame('0', $result['content'],
            'String "0" is valid content and should be preserved');
    }

    public function test_headline_takes_precedence_when_both_present() {
        $row = [
            'headline' => [
                'title' => 'Headline',
                'content' => null
            ],
            'article' => [
                'title' => 'Article',
                'content' => '<p>Article content</p>'
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertSame('Headline', $result['title'],
            'Headline wrapper should take precedence over article');
        $this->assertSame('', $result['content'],
            'Headline null content should be fixed');
    }

    public function test_content_is_always_string_for_diskcache() {
        $test_cases = [
            ['headline' => ['title' => 'Test', 'content' => null]],
            ['headline' => ['title' => 'Test']],
            ['article' => ['title' => 'Test', 'content' => null]],
            ['article' => ['title' => 'Test']],
        ];

        foreach ($test_cases as $index => $row) {
            $result = $this->plugin->hook_render_article_api($row);

            $this->assertIsString($result['content'] ?? null,
                "Test case $index: Content must be string type to prevent TypeError in DiskCache::rewrite_urls()");
        }
    }

    // =====================================================================
    // DISPLAY-TIME TRACKING PIXEL STRIPPING (retroactive backlog cleanup)
    // =====================================================================

    public function test_strips_dimension_pixel_from_stored_content() {
        // Simulates an article stored BEFORE import-time stripping existed:
        // width/height still present because Feature 1 never ran on it
        $row = [
            'headline' => [
                'title' => 'Old Article',
                'content' => '<p>Text</p><img src="https://example.com/beacon.gif" width="1" height="1">'
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertStringNotContainsString('<img', $result['content'],
            'Should strip a 1x1 pixel from old stored content at display time');
        $this->assertStringContainsString('<p>Text</p>', $result['content'],
            'Should leave surrounding content intact');
    }

    public function test_strips_url_pattern_pixel_from_stored_content() {
        // NPR-style beacon with no declared dimensions; the URL is still the
        // original here because this hook runs before DiskCache::rewrite_urls()
        $row = [
            'headline' => [
                'title' => 'Old NPR Article',
                'content' => "<p>Text</p><img src='https://media.npr.org/include/images/tracking/npr-rss-pixel.png?story=x' />"
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertStringNotContainsString('npr-rss-pixel', $result['content'],
            'Should strip a tracking-URL pixel from old stored content at display time');
    }

    public function test_preserves_normal_images_in_content() {
        $content = '<img src="https://example.com/photos/sunset.jpg" alt="Sunset"><p>Text</p>';
        $row = [
            'headline' => [
                'title' => 'Article',
                'content' => $content
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertStringContainsString('src="https://example.com/photos/sunset.jpg"', $result['content'],
            'Normal images must survive display-time stripping');
    }

    public function test_clears_tracking_pixel_flavor_image() {
        // Core computes flavor_image from raw stored content before this hook,
        // so on old articles it can be the pixel itself - it loads fine as a
        // 1x1, so client-side onerror fallbacks never fire
        $row = [
            'headline' => [
                'title' => 'Old Article',
                'content' => '<p>Text</p>',
                'flavor_image' => 'https://media.npr.org/include/images/tracking/npr-rss-pixel.png?story=x'
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertSame('', $result['flavor_image'],
            'flavor_image pointing at a tracking pixel should be cleared');
    }

    public function test_preserves_normal_flavor_image() {
        $row = [
            'headline' => [
                'title' => 'Article',
                'content' => '<p>Text</p>',
                'flavor_image' => 'https://example.com/photos/sunset.jpg'
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertSame('https://example.com/photos/sunset.jpg', $result['flavor_image'],
            'Normal flavor_image should pass through unchanged');
    }

    public function test_disabled_setting_skips_display_time_stripping() {
        $plugin = $this->makePluginWithStripDisabled();

        $pixel_url = 'https://media.npr.org/include/images/tracking/npr-rss-pixel.png?story=x';
        $row = [
            'headline' => [
                'title' => 'Article',
                'content' => '<img src="' . $pixel_url . '"><img src="https://example.com/b.gif" width="1" height="1">',
                'flavor_image' => $pixel_url
            ]
        ];

        $result = $plugin->hook_render_article_api($row);

        $this->assertStringContainsString('npr-rss-pixel', $result['content'],
            'URL-pattern pixel should survive when the setting is disabled');
        $this->assertStringContainsString('width="1"', $result['content'],
            'Dimension pixel should survive when the setting is disabled');
        $this->assertSame($pixel_url, $result['flavor_image'],
            'flavor_image should survive when the setting is disabled');
    }
}
