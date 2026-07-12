<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Af_Enhance_Content;

/**
 * Test suite for Enclosure URL Upgrading
 *
 * Tests verify that the plugin correctly:
 * 1. Extracts images from article pages
 * 2. Matches enclosure URLs to page images
 * 3. Upgrades low-resolution URLs to high-resolution
 * 4. Handles various URL patterns (BBC, CDN, query params)
 */
class Af_Enhance_Content_Upgrade_Test extends TestCase {

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
    // PAGE IMAGE EXTRACTION TESTS
    // =====================================================================

    public function test_extracts_images_with_srcset_from_page() {
        $html = '<html><body>
            <img src="thumb.jpg" srcset="small.jpg 300w, large.jpg 1200w">
        </body></html>';

        $result = $this->callPrivateMethod('extract_page_images', [$html]);

        $this->assertCount(1, $result);
        $this->assertEquals('thumb.jpg', $result[0]['src']);
        $this->assertEquals('large.jpg', $result[0]['highest_res']);
    }

    public function test_extracts_multiple_images_from_page() {
        $html = '<html><body>
            <img src="thumb1.jpg" srcset="large1.jpg 1200w">
            <p>Text</p>
            <img src="thumb2.jpg" srcset="large2.jpg 1200w">
        </body></html>';

        $result = $this->callPrivateMethod('extract_page_images', [$html]);

        $this->assertCount(2, $result);
        $this->assertEquals('large1.jpg', $result[0]['highest_res']);
        $this->assertEquals('large2.jpg', $result[1]['highest_res']);
    }

    public function test_extracts_src_when_no_srcset() {
        $html = '<html><body>
            <img src="image.jpg">
        </body></html>';

        $result = $this->callPrivateMethod('extract_page_images', [$html]);

        $this->assertCount(1, $result);
        $this->assertEquals('image.jpg', $result[0]['src']);
        $this->assertNull($result[0]['highest_res']);
    }

    public function test_returns_empty_when_no_images_found() {
        $html = '<html><body>
            <p>Just text, no images</p>
        </body></html>';

        $result = $this->callPrivateMethod('extract_page_images', [$html]);

        $this->assertEmpty($result);
    }

    public function test_handles_images_without_src() {
        $html = '<html><body>
            <img alt="Missing src">
        </body></html>';

        $result = $this->callPrivateMethod('extract_page_images', [$html]);

        $this->assertEmpty($result, 'Should skip images without src');
    }

    // =====================================================================
    // URL MATCHING TESTS
    // =====================================================================

    public function test_matches_same_filename() {
        $enclosure_url = 'https://example.com/image.jpg';
        $page_images = [
            [
                'src' => 'https://example.com/image.jpg',
                'highest_res' => 'https://example.com/image-large.jpg',
                'srcset' => 'image.jpg 300w, image-large.jpg 1200w'
            ]
        ];

        $result = $this->callPrivateMethod('match_and_upgrade_url', [$enclosure_url, $page_images]);

        $this->assertEquals('https://example.com/image-large.jpg', $result,
            'Should return highest res when filenames match');
    }

    public function test_matches_filename_without_extension() {
        $enclosure_url = 'https://example.com/photo.jpg';
        $page_images = [
            [
                'src' => 'https://cdn.example.com/photo.jpg',
                'highest_res' => 'https://cdn.example.com/photo-hd.jpg',
                'srcset' => null
            ]
        ];

        $result = $this->callPrivateMethod('match_and_upgrade_url', [$enclosure_url, $page_images]);

        $this->assertEquals('https://cdn.example.com/photo-hd.jpg', $result,
            'Should match by filename even on different domains');
    }

    public function test_matches_bbc_style_ws_paths() {
        $enclosure_url = 'https://ichef.bbci.co.uk/ace/ws/240/image.jpg';
        $page_images = [
            [
                'src' => 'https://ichef.bbci.co.uk/ace/ws/240/image.jpg',
                'highest_res' => 'https://ichef.bbci.co.uk/ace/ws/1024/image.jpg',
                'srcset' => 'image.jpg 240w, image.jpg 1024w'
            ]
        ];

        $result = $this->callPrivateMethod('match_and_upgrade_url', [$enclosure_url, $page_images]);

        $this->assertEquals('https://ichef.bbci.co.uk/ace/ws/1024/image.jpg', $result,
            'Should match BBC-style URLs with /ws/SIZE/ patterns');
    }

    public function test_is_same_image_different_size_bbc_pattern() {
        $url1 = 'https://ichef.bbci.co.uk/ace/ws/240/cpsprodpb/12345/production/_123456789_image.jpg';
        $url2 = 'https://ichef.bbci.co.uk/ace/ws/1024/cpsprodpb/12345/production/_123456789_image.jpg';

        $result = $this->callPrivateMethod('is_same_image_different_size', [$url1, $url2]);

        $this->assertTrue($result, 'Should recognize BBC URLs as same image with different sizes');
    }

    public function test_is_same_image_different_size_width_suffix() {
        $url1 = 'https://example.com/image_w240.jpg';
        $url2 = 'https://example.com/image_w1024.jpg';

        $result = $this->callPrivateMethod('is_same_image_different_size', [$url1, $url2]);

        $this->assertTrue($result, 'Should recognize _wXXX suffix pattern');
    }

    public function test_is_same_image_different_size_different_images() {
        $url1 = 'https://example.com/image1.jpg';
        $url2 = 'https://example.com/image2.jpg';

        $result = $this->callPrivateMethod('is_same_image_different_size', [$url1, $url2]);

        $this->assertFalse($result, 'Should return false for different images');
    }

    public function test_matches_with_query_parameters() {
        $enclosure_url = 'https://example.com/image.jpg?w=300&h=200';
        $page_images = [
            [
                'src' => 'https://example.com/image.jpg?w=300&h=200',
                'highest_res' => 'https://example.com/image.jpg?w=1200&h=800',
                'srcset' => null
            ]
        ];

        $result = $this->callPrivateMethod('match_and_upgrade_url', [$enclosure_url, $page_images]);

        $this->assertEquals('https://example.com/image.jpg?w=1200&h=800', $result,
            'Should match images with query parameters');
    }

    public function test_does_not_match_different_images() {
        $enclosure_url = 'https://example.com/photo1.jpg';
        $page_images = [
            [
                'src' => 'https://example.com/photo2.jpg',
                'highest_res' => 'https://example.com/photo2-large.jpg',
                'srcset' => null
            ]
        ];

        $result = $this->callPrivateMethod('match_and_upgrade_url', [$enclosure_url, $page_images]);

        $this->assertNull($result, 'Should return null when no match found');
    }

    public function test_returns_null_for_invalid_enclosure_url() {
        $enclosure_url = 'not-a-valid-url';
        $page_images = [
            ['src' => 'https://example.com/image.jpg', 'highest_res' => null, 'srcset' => null]
        ];

        $result = $this->callPrivateMethod('match_and_upgrade_url', [$enclosure_url, $page_images]);

        $this->assertNull($result);
    }

    // =====================================================================
    // UPGRADE ENCLOSURE URLS TESTS
    // =====================================================================

    public function test_upgrades_low_res_to_high_res() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg';
        $enclosure->type = 'image/jpeg';

        $article = [
            'title' => 'Test',
            'content' => 'Content',
            'enclosures' => [$enclosure]
        ];

        $html = '<html><body>
            <img src="https://example.com/image.jpg"
                 srcset="https://example.com/image.jpg 300w, https://example.com/image-hd.jpg 1200w">
        </body></html>';

        $result = $this->callPrivateMethod('upgrade_enclosure_urls', [$article, $html]);

        $this->assertEquals('https://example.com/image-hd.jpg', $result['enclosures'][0]->link,
            'Should upgrade enclosure URL to highest resolution');
    }

    public function test_skips_non_image_enclosures() {
        $audio = new \stdClass();
        $audio->link = 'https://example.com/audio.mp3';
        $audio->type = 'audio/mpeg';

        $image = new \stdClass();
        $image->link = 'https://example.com/image.jpg';
        $image->type = 'image/jpeg';

        $article = [
            'title' => 'Test',
            'enclosures' => [$audio, $image]
        ];

        $html = '<img src="https://example.com/image.jpg" srcset="image-hd.jpg 1200w">';

        $result = $this->callPrivateMethod('upgrade_enclosure_urls', [$article, $html]);

        $this->assertEquals('https://example.com/audio.mp3', $result['enclosures'][0]->link,
            'Audio enclosure should remain unchanged');
        $this->assertNotEquals('https://example.com/image.jpg', $result['enclosures'][1]->link,
            'Image enclosure should be upgraded');
    }

    public function test_handles_multiple_image_enclosures() {
        $enc1 = new \stdClass();
        $enc1->link = 'https://example.com/image1.jpg';
        $enc1->type = 'image/jpeg';

        $enc2 = new \stdClass();
        $enc2->link = 'https://example.com/image2.jpg';
        $enc2->type = 'image/jpeg';

        $article = [
            'title' => 'Test',
            'enclosures' => [$enc1, $enc2]
        ];

        $html = '<html><body>
            <img src="image1.jpg" srcset="image1-hd.jpg 1200w">
            <img src="image2.jpg" srcset="image2-hd.jpg 1200w">
        </body></html>';

        $result = $this->callPrivateMethod('upgrade_enclosure_urls', [$article, $html]);

        $this->assertStringContainsString('image1-hd.jpg', $result['enclosures'][0]->link);
        $this->assertStringContainsString('image2-hd.jpg', $result['enclosures'][1]->link);
    }

    public function test_handles_page_with_no_srcset() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg';
        $enclosure->type = 'image/jpeg';

        $article = [
            'title' => 'Test',
            'enclosures' => [$enclosure]
        ];

        $html = '<html><body>
            <img src="https://example.com/other-image.jpg">
        </body></html>';

        $result = $this->callPrivateMethod('upgrade_enclosure_urls', [$article, $html]);

        $this->assertEquals('https://example.com/image.jpg', $result['enclosures'][0]->link,
            'Should leave enclosure unchanged when no match found');
    }

    public function test_handles_empty_enclosures_array() {
        $article = [
            'title' => 'Test',
            'enclosures' => []
        ];

        $html = '<img src="image.jpg" srcset="image-hd.jpg 1200w">';

        $result = $this->callPrivateMethod('upgrade_enclosure_urls', [$article, $html]);

        $this->assertEmpty($result['enclosures']);
    }

    public function test_handles_missing_enclosures_key() {
        $article = [
            'title' => 'Test',
            'content' => 'Content'
        ];

        $html = '<img src="image.jpg">';

        $result = $this->callPrivateMethod('upgrade_enclosure_urls', [$article, $html]);

        $this->assertEquals($article, $result, 'Should return unchanged when no enclosures');
    }

    public function test_handles_enclosure_without_link() {
        $enclosure = new \stdClass();
        $enclosure->type = 'image/jpeg';
        // No link property

        $article = [
            'title' => 'Test',
            'enclosures' => [$enclosure]
        ];

        $html = '<img src="image.jpg">';

        $result = $this->callPrivateMethod('upgrade_enclosure_urls', [$article, $html]);

        $this->assertNotNull($result, 'Should handle enclosure without link gracefully');
    }

    public function test_prefers_highest_res_over_src() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg';
        $enclosure->type = 'image/jpeg';

        $article = [
            'title' => 'Test',
            'enclosures' => [$enclosure]
        ];

        // Must have exact filename match for plugin to upgrade
        $html = '<img src="https://example.com/image.jpg"
                      srcset="https://example.com/image.jpg 300w,
                              https://example.com/image-medium.jpg 600w,
                              https://example.com/image-hd.jpg 1200w">';

        $result = $this->callPrivateMethod('upgrade_enclosure_urls', [$article, $html]);

        $this->assertEquals('https://example.com/image-hd.jpg', $result['enclosures'][0]->link,
            'Should use highest resolution from srcset when filename matches');
    }

    // =====================================================================
    // CONDITIONAL FETCH LOGIC TESTS
    // =====================================================================

    public function test_article_has_images_detects_image_enclosures() {
        $enclosure = new \stdClass();
        $enclosure->type = 'image/jpeg';

        $article = [
            'content' => 'Content',
            'enclosures' => [$enclosure]
        ];

        $result = $this->callPrivateMethod('article_has_images', [$article]);

        $this->assertTrue($result, 'Should detect image enclosures');
    }

    public function test_article_has_images_detects_inline_img_tags() {
        $article = [
            'content' => '<p>Text</p><img src="image.jpg"><p>More text</p>',
            'enclosures' => []
        ];

        $result = $this->callPrivateMethod('article_has_images', [$article]);

        $this->assertTrue($result, 'Should detect inline img tags');
    }

    public function test_article_has_images_returns_false_for_no_images() {
        $article = [
            'content' => '<p>Just text, no images</p>',
            'enclosures' => []
        ];

        $result = $this->callPrivateMethod('article_has_images', [$article]);

        $this->assertFalse($result, 'Should return false when no images');
    }

    public function test_article_has_images_ignores_non_image_enclosures() {
        $enclosure = new \stdClass();
        $enclosure->type = 'audio/mpeg';

        $article = [
            'content' => 'Content',
            'enclosures' => [$enclosure]
        ];

        $result = $this->callPrivateMethod('article_has_images', [$article]);

        $this->assertFalse($result, 'Should ignore non-image enclosures');
    }

    /**
     * Test: article_has_images() ignores small icon-sized <img> tags (e.g. social
     * share buttons some themes/plugins inject into post content), so the og:image
     * fallback still fires for feeds whose only inline "images" are icons.
     */
    public function test_article_has_images_ignores_icon_sized_images() {
        $article = [
            'content' => '<img src="facebook.png" width="24" height="24" alt="Facebook">' .
                '<img src="twitter.png" width="24" height="24" alt="twitter">',
            'enclosures' => []
        ];

        $result = $this->callPrivateMethod('article_has_images', [$article]);

        $this->assertFalse($result,
            'Should not count small share-icon images as a real article image');
    }

    /**
     * Test: a real content image alongside icon-sized images is still detected.
     */
    public function test_article_has_images_detects_real_image_among_icons() {
        $article = [
            'content' => '<img src="facebook.png" width="24" height="24" alt="Facebook">' .
                '<img src="photo.jpg" width="800" height="600" alt="Photo">',
            'enclosures' => []
        ];

        $result = $this->callPrivateMethod('article_has_images', [$article]);

        $this->assertTrue($result, 'Should detect the real content image among icons');
    }

    /**
     * Test: an <img> with no declared width/height still counts as a real image,
     * since the icon heuristic can't judge undeclared dimensions (matches the
     * existing NPR/The Verge full-content-image behavior).
     */
    public function test_article_has_images_treats_undeclared_dimensions_as_real() {
        $article = [
            'content' => '<img src="https://npr.example.com/photo.jpg" alt="Photo">',
            'enclosures' => []
        ];

        $result = $this->callPrivateMethod('article_has_images', [$article]);

        $this->assertTrue($result,
            'Images without declared width/height should count as real content');
    }

    /**
     * Test: is_icon_sized_image() boundary - 50x50 is icon-sized (inclusive),
     * 51x51 is not.
     */
    public function test_is_icon_sized_image_boundary() {
        $icon = $this->callPrivateMethod('is_icon_sized_image', ['<img width="50" height="50">']);
        $this->assertTrue($icon, '50x50 should be treated as icon-sized');

        $notIcon = $this->callPrivateMethod('is_icon_sized_image', ['<img width="51" height="51">']);
        $this->assertFalse($notIcon, '51x51 should not be treated as icon-sized');
    }

    /**
     * Regression test: enhance_inline_images() (Feature 1) unconditionally strips
     * width/height attributes from every surviving <img> tag. If article_has_images()
     * were called on that already-enhanced content instead of the original, the size
     * evidence needed to recognize icon-sized share buttons would already be gone,
     * silently defeating the icon filter and blocking the og:image fallback for
     * exactly the feeds it's meant to help (e.g. sites using "Social Media Feather").
     * hook_article_filter() must evaluate article_has_images() against the pristine
     * pre-enhancement content, not the post-enhancement one.
     */
    public function test_enhance_inline_images_erases_size_evidence_used_by_has_images_check() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="facebook.png" width="24" height="24" alt="Facebook">',
            'enclosures' => []
        ];

        // Confirm the bug scenario: checking has-images on the pre-enhancement
        // content correctly recognizes the icon and reports no real image.
        $this->assertFalse(
            $this->callPrivateMethod('article_has_images', [$article]),
            'Icon-sized image should not count as a real image before enhancement'
        );

        // enhance_inline_images() strips width/height as part of its normal Step 4
        // ("allows natural high-res display") - this is the mutation that must not
        // be allowed to reach the has-images decision.
        $enhanced = $this->callPrivateMethod('enhance_inline_images', [$article]);
        $this->assertStringNotContainsString('width=', $enhanced['content'],
            'Sanity check: enhance_inline_images should have stripped width/height');

        // Checking has-images on the already-enhanced content would incorrectly
        // report a real image, since the size evidence is gone - this is exactly
        // why hook_article_filter must use the pre-enhancement copy.
        $this->assertTrue(
            $this->callPrivateMethod('article_has_images', [$enhanced]),
            'Demonstrates the bug: post-enhancement content loses the size evidence, ' .
            'so hook_article_filter must check article_has_images() before Feature 1 runs'
        );
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
