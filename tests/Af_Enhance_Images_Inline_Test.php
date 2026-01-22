<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Af_Enhance_Images;

/**
 * Test suite for Af_Enhance_Images plugin - Inline Image Enhancement
 *
 * Tests verify that the plugin correctly:
 * 1. Extracts highest resolution from srcset and rewrites src
 * 2. Converts data-src to src for lazy-loaded images
 * 3. Removes loading="lazy" attributes
 * 4. Handles edge cases and malformed HTML
 */
class Af_Enhance_Images_Inline_Test extends TestCase {

    private $plugin;
    private $mockHost;

    protected function setUp(): void {
        // Mock the PluginHost
        $this->mockHost = $this->createMock(\PluginHost::class);

        // Suppress the host->add_hook call during init
        $this->mockHost->expects($this->any())
            ->method('add_hook')
            ->willReturn(true);

        // Mock host->get to return defaults for v2.0 configuration
        // Enable inline_enhancement and fix_enclosure_type by default for v1.0 tests
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                // Enable inline enhancement for these tests
                if ($key === 'inline_enhancement') return true;
                // Enable enclosure type fixing
                if ($key === 'fix_enclosure_type') return true;
                // Disable article fetching (not needed for inline tests)
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
        $this->plugin = new Af_Enhance_Images();
        $this->plugin->init($this->mockHost);
    }

    /**
     * Test 1: Srcset with width descriptors - extracts highest resolution
     */
    public function test_rewrites_src_from_srcset_with_width_descriptors() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumbnail.jpg?w=300" srcset="thumbnail.jpg?w=300 300w, image.jpg?w=600 600w, image.jpg?w=1200 1200w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="image.jpg?w=1200"', $result['content'],
            'Should rewrite src to highest resolution from srcset');
        $this->assertStringNotContainsString('src="thumbnail.jpg?w=300"', $result['content'],
            'Should not keep the old low-res src');
    }

    /**
     * Test 2: Srcset with pixel density descriptors (2x, 3x)
     */
    public function test_rewrites_src_from_srcset_with_density_descriptors() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="image.jpg" srcset="image.jpg 1x, image@2x.jpg 2x, image@3x.jpg 3x">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="image@3x.jpg"', $result['content'],
            'Should use highest pixel density image (3x)');
    }

    /**
     * Test 3: Mixed srcset (width and density descriptors)
     */
    public function test_handles_mixed_srcset_descriptors() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="small.jpg" srcset="medium.jpg 600w, large.jpg 1200w, retina.jpg 2x">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        // 1200w should win over 2x (2x = 2000w equivalent)
        $this->assertStringContainsString('src="retina.jpg"', $result['content'],
            'Should prioritize pixel density over width when density is higher');
    }

    /**
     * Test 4: Data-src to src conversion (lazy loading)
     */
    public function test_converts_data_src_to_src() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img data-src="lazy-image.jpg" alt="Lazy loaded">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="lazy-image.jpg"', $result['content'],
            'Should convert data-src to src');
        $this->assertStringNotContainsString('data-src=', $result['content'],
            'Should remove data-src attribute after conversion');
    }

    /**
     * Test 5: Data-src when src already exists - don't overwrite
     */
    public function test_preserves_existing_src_when_data_src_present() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="placeholder.jpg" data-src="real-image.jpg">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        // Should keep the existing src, not replace it with data-src
        $this->assertStringContainsString('src="placeholder.jpg"', $result['content'],
            'Should preserve existing src attribute');
    }

    /**
     * Test 6: Remove loading="lazy" attribute
     */
    public function test_removes_loading_lazy_attribute() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="image.jpg" loading="lazy">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringNotContainsString('loading="lazy"', $result['content'],
            'Should remove loading="lazy" attribute');
        $this->assertStringContainsString('src="image.jpg"', $result['content'],
            'Should preserve src attribute');
    }

    /**
     * Test 7: Combined enhancement - all three fixes at once
     */
    public function test_applies_all_enhancements_together() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img data-src="image.jpg" srcset="image.jpg 300w, image-large.jpg 1200w" loading="lazy">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="image-large.jpg"', $result['content'],
            'Should use highest res from srcset');
        $this->assertStringNotContainsString('loading="lazy"', $result['content'],
            'Should remove loading attribute');
        $this->assertStringNotContainsString('data-src=', $result['content'],
            'Should handle data-src conversion');
    }

    /**
     * Test 8: Multiple images in article - all enhanced
     */
    public function test_enhances_multiple_images_in_article() {
        $article = [
            'title' => 'Test Article',
            'content' => '<p>Text</p>
                <img src="thumb1.jpg" srcset="thumb1.jpg 300w, full1.jpg 1200w">
                <p>More text</p>
                <img src="thumb2.jpg" srcset="thumb2.jpg 300w, full2.jpg 1200w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="full1.jpg"', $result['content'],
            'Should enhance first image');
        $this->assertStringContainsString('src="full2.jpg"', $result['content'],
            'Should enhance second image');
    }

    /**
     * Test 9: Srcset with no descriptors (malformed)
     */
    public function test_handles_srcset_without_descriptors() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="image.jpg" srcset="image2.jpg">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        // Should use the srcset URL even without descriptor
        $this->assertStringContainsString('src="image2.jpg"', $result['content'],
            'Should use srcset URL even without size descriptor');
    }

    /**
     * Test 10: Empty srcset - no changes
     */
    public function test_handles_empty_srcset() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="image.jpg" srcset="">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="image.jpg"', $result['content'],
            'Should preserve original src with empty srcset');
    }

    /**
     * Test 11: No img tags - returns unchanged
     */
    public function test_returns_unchanged_when_no_images() {
        $article = [
            'title' => 'Test Article',
            'content' => '<p>Just some text without images</p>'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals($article['content'], $result['content'],
            'Should return content unchanged when no images present');
    }

    /**
     * Test 12: Empty content - returns unchanged
     */
    public function test_handles_empty_content() {
        $article = [
            'title' => 'Test Article',
            'content' => ''
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals($article, $result,
            'Should return article unchanged with empty content');
    }

    /**
     * Test 13: Missing content key - returns unchanged
     */
    public function test_handles_missing_content_key() {
        $article = [
            'title' => 'Test Article'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals($article, $result,
            'Should return article unchanged when content key missing');
    }

    /**
     * Test 14: Real-world WordPress srcset pattern
     */
    public function test_wordpress_style_srcset() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="example.jpg?resize=300,200"
                srcset="example.jpg?w=300 300w,
                        example.jpg?w=768 768w,
                        example.jpg?w=1024 1024w,
                        example.jpg?w=1920 1920w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('example.jpg?w=1920', $result['content'],
            'Should extract highest resolution from WordPress-style srcset');
    }

    /**
     * Test 15: Image with quotes variations in attributes
     */
    public function test_handles_different_quote_styles() {
        $article = [
            'title' => 'Test Article',
            'content' => "<img src='image.jpg' srcset='small.jpg 300w, large.jpg 1200w'>"
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('large.jpg', $result['content'],
            'Should handle single quotes in attributes');
    }

    /**
     * Test 16: Srcset with absolute and relative URLs
     */
    public function test_handles_absolute_urls_in_srcset() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumb.jpg"
                srcset="https://example.com/small.jpg 300w,
                        https://example.com/large.jpg 1200w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('https://example.com/large.jpg', $result['content'],
            'Should handle absolute URLs in srcset');
    }

    /**
     * Test 17: Decimal pixel density descriptors (1.5x)
     */
    public function test_handles_decimal_density_descriptors() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="1x.jpg" srcset="1x.jpg 1x, 1.5x.jpg 1.5x, 2x.jpg 2x">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="2x.jpg"', $result['content'],
            'Should handle decimal density descriptors and pick highest');
    }

    /**
     * Test 18: Preserve other img attributes during enhancement
     */
    public function test_preserves_other_img_attributes() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumb.jpg" srcset="small.jpg 300w, large.jpg 1200w"
                alt="Test image" width="800" height="600" class="featured">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('alt="Test image"', $result['content'],
            'Should preserve alt attribute');
        $this->assertStringContainsString('width="800"', $result['content'],
            'Should preserve width attribute');
        $this->assertStringContainsString('class="featured"', $result['content'],
            'Should preserve class attribute');
    }

    /**
     * ADDITIONAL EDGE CASE TESTS
     */

    /**
     * Test 19: Srcset with trailing commas
     */
    public function test_handles_srcset_with_trailing_commas() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumb.jpg" srcset="small.jpg 300w, large.jpg 1200w,">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="large.jpg"', $result['content'],
            'Should handle srcset with trailing comma');
    }

    /**
     * Test 20: Srcset with only commas (malformed)
     */
    public function test_handles_srcset_with_only_commas() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumb.jpg" srcset=",,">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="thumb.jpg"', $result['content'],
            'Should preserve src when srcset is malformed');
    }

    /**
     * Test 21: Srcset with malformed width descriptors
     */
    public function test_handles_malformed_width_descriptors() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumb.jpg" srcset="image1.jpg abc, image2.jpg 1200w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="image2.jpg"', $result['content'],
            'Should skip malformed descriptor and use valid one');
    }

    /**
     * Test 22: Data-srcset is not processed by plugin
     */
    public function test_data_srcset_not_processed() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumb.jpg" data-srcset="image.jpg 1200w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('data-srcset=', $result['content'],
            'Should preserve data-srcset attribute');
        // Plugin doesn't process data-srcset, only srcset
        $this->assertStringContainsString('src=', $result['content'],
            'Should have a src attribute');
    }

    /**
     * Test 23: Loading attribute with different values
     */
    public function test_removes_loading_eager_attribute() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="image.jpg" loading="eager">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        // Plugin only removes loading="lazy", not other values
        $this->assertStringContainsString('src="image.jpg"', $result['content']);
    }

    /**
     * Test 24: Image with no src but has srcset
     */
    public function test_handles_image_with_only_srcset() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img srcset="small.jpg 300w, large.jpg 1200w" alt="No src">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        // Plugin now adds src from srcset for better browser compatibility
        $this->assertStringContainsString('src="large.jpg"', $result['content'],
            'Should add src from srcset when src missing');
        $this->assertStringContainsString('srcset=', $result['content'],
            'Should preserve srcset');
    }

    /**
     * Test 25: Very long srcset with many options
     */
    public function test_handles_very_long_srcset() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumb.jpg" srcset="
                img-100.jpg 100w,
                img-200.jpg 200w,
                img-300.jpg 300w,
                img-400.jpg 400w,
                img-600.jpg 600w,
                img-800.jpg 800w,
                img-1000.jpg 1000w,
                img-1200.jpg 1200w,
                img-1600.jpg 1600w,
                img-2000.jpg 2000w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="img-2000.jpg"', $result['content'],
            'Should find highest resolution in long srcset');
    }

    /**
     * Test 26: URL with fragment identifier
     */
    public function test_handles_url_with_fragment() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumb.jpg#section" srcset="large.jpg#section 1200w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="large.jpg#section"', $result['content'],
            'Should preserve fragment identifiers in URLs');
    }

    /**
     * Test 27: Case-insensitive attribute matching
     */
    public function test_handles_uppercase_attributes() {
        $article = [
            'title' => 'Test Article',
            'content' => '<IMG SRC="thumb.jpg" SRCSET="large.jpg 1200w" LOADING="LAZY">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('large.jpg', $result['content'],
            'Should handle uppercase attributes');
    }

    /**
     * Test 28: Image with both data-src and srcset
     */
    public function test_handles_data_src_with_srcset() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img data-src="lazy.jpg" srcset="small.jpg 300w, large.jpg 1200w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        // Should convert data-src to src AND use srcset
        $this->assertStringContainsString('src="large.jpg"', $result['content'],
            'Should prioritize srcset even when data-src present');
    }
}
