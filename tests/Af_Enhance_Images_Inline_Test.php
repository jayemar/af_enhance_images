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
     * Test 1b: Srcset selection is capped - smallest candidate at or above the
     * 1600w target wins over multi-thousand-pixel originals (bandwidth: the
     * chosen URL gets cached server-side and proxied to every client).
     */
    public function test_srcset_selection_capped_at_target_width() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumb.jpg" srcset="small.jpg 800w, medium.jpg 1600w, huge.jpg 3200w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="medium.jpg"', $result['content'],
            'Should pick the smallest candidate at or above 1600w, not the absolute largest');
    }

    /**
     * Test 1c: Real-world NPR-style srcset where the original is a 4764w camera
     * image - the ~2000w web variant should be selected instead.
     */
    public function test_srcset_avoids_oversized_camera_originals() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumb.jpg" srcset="img-800.jpg 800w, img-1200.jpg 1200w, img-2000.jpg 2000w, img-original.jpg 4764w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="img-2000.jpg"', $result['content'],
            'Should avoid the oversized original when a big-enough variant exists');
    }

    /**
     * Test 1d: When every candidate is below the target, the largest available
     * still wins (the cap must never downgrade quality).
     */
    public function test_srcset_below_target_picks_largest() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumb.jpg" srcset="a.jpg 300w, b.jpg 768w, c.jpg 1024w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="c.jpg"', $result['content'],
            'Should fall back to the largest candidate when none reach the target');
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

        // 2x (~2000w equivalent) is the smallest candidate over the 1600w
        // target, so it wins over the heavier 3x
        $this->assertStringContainsString('src="image@2x.jpg"', $result['content'],
            'Should use the smallest density at or above the target width (2x)');
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
     * Test 18: Preserve non-sizing attributes during enhancement. Sizing
     * attributes (width/height/srcset/sizes) are intentionally REMOVED by
     * Step 4 so readers display the image at natural resolution.
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
        $this->assertStringContainsString('class="featured"', $result['content'],
            'Should preserve class attribute');
        $this->assertStringNotContainsString('width=', $result['content'],
            'Sizing attributes are intentionally removed (Step 4)');
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
     * Test 22: Data-srcset is normalized to srcset and highest resolution extracted
     */
    public function test_data_srcset_normalized_to_srcset() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="thumb.jpg" data-srcset="image.jpg 1200w">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringNotContainsString('data-srcset=', $result['content'],
            'Should convert data-srcset to srcset');
        $this->assertStringContainsString('src="image.jpg"', $result['content'],
            'Should extract highest resolution from data-srcset');
    }

    /**
     * Test 29: Data URI placeholder src + data-src (Shopify lazy loading pattern)
     */
    public function test_replaces_data_uri_placeholder_src_with_data_src() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-src="https://cdn.shopify.com/comic.png" class="lazyload">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="https://cdn.shopify.com/comic.png"', $result['content'],
            'Should replace data URI placeholder with real URL from data-src');
        $this->assertStringNotContainsString('data:image/gif', $result['content'],
            'Should remove the data URI placeholder');
        $this->assertStringNotContainsString('data-src=', $result['content'],
            'Should remove data-src after promotion');
    }

    /**
     * Test 30: Data URI placeholder src + data-srcset (Shopify responsive lazy loading)
     */
    public function test_replaces_data_uri_placeholder_with_best_from_data_srcset() {
        $article = [
            'title' => 'Test Article',
            'content' => '<img src="data:image/gif;base64,R0lGODlhAQABAAAAACH5BAEKAAEALAAAAAABAAEAAAICTAEAOw==" data-src="https://cdn.shopify.com/comic.png?width=360" data-srcset="https://cdn.shopify.com/comic.png?width=360 360w, https://cdn.shopify.com/comic.png?width=1100 1100w" class="lazyload">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="https://cdn.shopify.com/comic.png?width=1100"', $result['content'],
            'Should use highest resolution from data-srcset');
        $this->assertStringNotContainsString('data:image/gif', $result['content'],
            'Should remove the data URI placeholder');
        $this->assertStringNotContainsString('data-srcset=', $result['content'],
            'Should remove data-srcset after normalization');
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

        // Plugin adds src from srcset for better browser compatibility, then
        // Step 4 intentionally removes the (now redundant) srcset attribute
        $this->assertStringContainsString('src="large.jpg"', $result['content'],
            'Should add src from srcset when src missing');
        $this->assertStringNotContainsString('srcset=', $result['content'],
            'srcset is intentionally removed after extraction (Step 4)');
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

        // 1600w exactly meets the target width, so it wins over 2000w
        $this->assertStringContainsString('src="img-1600.jpg"', $result['content'],
            'Should pick the smallest candidate at or above the target in a long srcset');
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

    /**
     * TEST GROUP: AMBIGUOUS SINGLE-QUOTED ATTRIBUTE RE-QUOTING
     *
     * Real-world case: NPR's RSS feed emits <img alt='...'> where the alt text
     * contains an apostrophe (e.g. "nation's"). PHP's strip_tags() - used by
     * TT-RSS core to compute the article-list excerpt - loses track of the tag
     * boundary at that internal apostrophe and swallows the following real
     * article text, producing an empty excerpt even though the reader (which
     * uses a real HTML parser) renders fine.
     */

    /**
     * Test 29: Single-quoted attribute with an internal apostrophe gets re-quoted
     */
    public function test_requotes_single_quoted_attr_with_internal_apostrophe() {
        $article = [
            'title' => 'Test Article',
            'content' => "<img src='https://example.com/x.jpg' alt='The nation's capital may be the focal point of the celebration.'/>"
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString(
            'alt="The nation\'s capital may be the focal point of the celebration."',
            $result['content'],
            'Should re-quote the ambiguous single-quoted alt attribute as double-quoted'
        );
        $this->assertStringNotContainsString("alt='The nation", $result['content'],
            'Should not leave the old single-quoted form');
    }

    /**
     * Test 30: The actual downstream failure mode is fixed - PHP's real
     * strip_tags() no longer swallows the text following the <img> tag.
     * This is the critical regression-proof test: it doesn't just check that
     * the tag "looks" re-quoted, it proves the real bug (empty excerpt) is gone.
     */
    public function test_strip_tags_survives_requoted_apostrophe_attr() {
        $article = [
            'title' => 'Test Article',
            'content' => "<img src='https://example.com/x.jpg' alt='The nation's capital may be the focal point of the celebration.'/><p>First real paragraph.</p><p>Second real paragraph.</p>"
        ];

        $result = $this->plugin->hook_article_filter($article);
        $stripped = strip_tags($result['content']);

        $this->assertStringContainsString('First real paragraph.', $stripped,
            'strip_tags() must no longer eat the text after the <img> tag');
        $this->assertStringContainsString('Second real paragraph.', $stripped,
            'strip_tags() must no longer eat the text after the <img> tag');
    }

    /**
     * Test 31: Single-quoted attributes with no internal apostrophe are left alone
     */
    public function test_leaves_single_quoted_attrs_without_apostrophe_unchanged() {
        $article = [
            'title' => 'Test Article',
            'content' => "<img src='image.jpg' srcset='small.jpg 300w, large.jpg 1200w'>"
        ];

        $result = $this->plugin->hook_article_filter($article);

        // srcset gets extracted to src and removed by existing steps regardless;
        // the point here is that re-quoting doesn't fire when there's no apostrophe
        $this->assertStringContainsString('large.jpg', $result['content']);
    }

    /**
     * Test 32: Value containing a literal double-quote is left as-is (rare,
     * unsafe to re-quote automatically)
     */
    public function test_skips_requoting_when_value_contains_double_quote() {
        $article = [
            'title' => 'Test Article',
            'content' => "<img src='image.jpg' alt='He said \"hi\" y'all'>"
        ];

        $result = $this->plugin->hook_article_filter($article);

        // Should not crash or mangle; the src attribute should still be processed normally
        $this->assertStringContainsString('image.jpg', $result['content']);
    }

    /**
     * Test 33: Multiple apostrophe-containing single-quoted attributes on the
     * same tag are all correctly bounded, and existing Step 4 logic (which
     * strips width) still works correctly afterward - proves no interference
     * between the new re-quoting step and the existing attribute-stripping steps.
     */
    public function test_requoting_handles_multiple_apostrophes_and_multiple_attrs() {
        $article = [
            'title' => 'Test Article',
            'content' => "<img src='image.jpg' alt='The nation's capital' title='It's a big day' width='300'>"
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('alt="The nation\'s capital"', $result['content']);
        $this->assertStringContainsString('title="It\'s a big day"', $result['content']);
        $this->assertStringNotContainsString('width=', $result['content'],
            'Existing Step 4 should still strip width after re-quoting');
    }

    /**
     * TEST GROUP: DOUBLE-ESCAPED ANCHOR TAG DECODING
     *
     * Real-world case: KPBS (Arc Publishing-style CMS) serializes a photo
     * credit link component to its RSS feed as literal, HTML-entity-escaped
     * markup instead of a real <a> tag - the CMS's own website renders the
     * same underlying data correctly via its own component/JS layer, but the
     * feed, as delivered to every subscriber, contains visible text like:
     *   (&lt;a href="https://example.com/staff/x" link-data="{...}"&gt;Jane Doe&lt;/a&gt;)
     * hook_render_article_api() runs at display time (every API response),
     * so it fixes both newly-imported and already-stored articles.
     */

    /**
     * Test 34: A double-escaped anchor tag with extra CMS attributes is
     * decoded back into a clean, real <a href>.
     */
    public function test_decodes_double_escaped_anchor_tag() {
        $row = [
            'headline' => [
                'title' => 'Test Article',
                'content' => '<figcaption>Credit <span>(&lt;a href="https://example.com/staff/jane-doe" data-cms-id="abc123" link-data="{"link":{"linkText":"Jane Doe"}}"&gt;Jane Doe&lt;/a&gt;)</span></figcaption>',
            ],
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertStringContainsString(
            '<a href="https://example.com/staff/jane-doe">Jane Doe</a>',
            $result['content'],
            'Should decode the escaped anchor into a clean real link'
        );
        $this->assertStringNotContainsString('&lt;a', $result['content'],
            'Should not leave any escaped tag markup behind');
        $this->assertStringNotContainsString('data-cms-id', $result['content'],
            'Should drop CMS-internal attributes, not carry them into the real tag');
    }

    /**
     * Test 35: Content with no escaped anchor tags is left completely
     * unchanged (the fast substring pre-check should skip the regex).
     */
    public function test_leaves_content_without_escaped_anchors_unchanged() {
        $row = [
            'headline' => [
                'title' => 'Test Article',
                'content' => '<p>Ordinary content with a real <a href="https://example.com">link</a>.</p>',
            ],
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertSame(
            '<p>Ordinary content with a real <a href="https://example.com">link</a>.</p>',
            $result['content']
        );
    }

    /**
     * Test 36: Multiple escaped anchors in the same article are each decoded
     * independently and correctly.
     */
    public function test_decodes_multiple_escaped_anchors() {
        $row = [
            'headline' => [
                'title' => 'Test Article',
                'content' => 'By &lt;a href="https://example.com/a"&gt;Alice&lt;/a&gt; and &lt;a href="https://example.com/b"&gt;Bob&lt;/a&gt;',
            ],
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertStringContainsString('<a href="https://example.com/a">Alice</a>', $result['content']);
        $this->assertStringContainsString('<a href="https://example.com/b">Bob</a>', $result['content']);
    }

    /**
     * Test 37: getHeadlines' excerpt is built from a content_preview that
     * core computes by truncating RAW content before HOOK_RENDER_ARTICLE_API
     * ever runs, so the anchor-tag fix above never reaches it on its own.
     * hook_query_headlines() must recompute content_preview from the fixed
     * content so the article-list excerpt matches the reader view.
     */
    public function test_query_headlines_fixes_escaped_anchor_in_excerpt() {
        $line = [
            'content' => 'Credit (&lt;a href="https://example.com/staff/jane-doe" data-cms-id="abc123"&gt;Jane Doe&lt;/a&gt;)',
            'content_preview' => 'Credit (&lt;a href="https://example.com/staff/jane-doe" data-cms-id="abc123"&gt;Jane Doe&lt;/a&gt;)',
        ];

        $result = $this->plugin->hook_query_headlines($line, 250);

        $this->assertStringContainsString('Jane Doe', $result['content_preview']);
        $this->assertStringNotContainsString('&lt;a', $result['content_preview']);
        $this->assertStringNotContainsString('data-cms-id', $result['content_preview']);
    }

    /**
     * Test 38: when the raw content is truncated mid-tag (no closing
     * &lt;/a&gt;), the excerpt-only fix must still work because it rebuilds
     * content_preview from the full content, not by patching the already-
     * truncated string.
     */
    public function test_query_headlines_handles_anchor_truncated_mid_attribute() {
        $line = [
            'content' => 'Credit (&lt;a href="https://example.com/staff/jane-doe" data-cms-id="abc123" link-data="{"link":{"linkText":"Jane Doe"}}"&gt;Jane Doe&lt;/a&gt;) and the rest of the article body continues on for a while after that.',
            'content_preview' => 'Credit (&lt;a href="https://example.com/staff/jane-doe" data-cms-id="abc123" link-data="{"li&hellip;',
        ];

        $result = $this->plugin->hook_query_headlines($line, 250);

        $this->assertStringNotContainsString('&lt;a', $result['content_preview']);
        $this->assertStringNotContainsString('data-cms-id', $result['content_preview']);
    }

    /**
     * Test 39: headlines without any escaped anchor are left untouched.
     */
    public function test_query_headlines_leaves_ordinary_excerpt_unchanged() {
        $line = [
            'content' => '<p>Ordinary content with no escaped markup.</p>',
            'content_preview' => 'Ordinary content with no escaped markup.',
        ];

        $result = $this->plugin->hook_query_headlines($line, 250);

        $this->assertSame('Ordinary content with no escaped markup.', $result['content_preview']);
    }
}
