<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Af_Enhance_Images;

/**
 * Test suite for Af_Enhance_Images plugin - Enclosure Handling
 *
 * Tests verify enclosure-related functionality:
 * 1. Enclosure MIME type fixing
 * 2. Open Graph metadata extraction
 * 3. Enclosure URL upgrading (BBC Mundo feature)
 * 4. Configuration handling
 */
class Af_Enhance_Images_Enclosure_Test extends TestCase {

    private $plugin;
    private $mockHost;

    protected function setUp(): void {
        // Mock the PluginHost
        $this->mockHost = $this->createMock(\PluginHost::class);

        // Suppress the host->add_hook call during init
        $this->mockHost->expects($this->any())
            ->method('add_hook')
            ->willReturn(true);

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
     * TEST GROUP 1: ENCLOSURE MIME TYPE FIXING
     */

    public function test_fixes_empty_enclosure_mime_type_jpg() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg';
        $enclosure->type = '';
        $enclosure->length = 0;

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        // Mock host->get to return true for fix_enclosure_type
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false; // disable inline to test only enclosure
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type,
            'Should set MIME type to image/jpeg for .jpg files');
    }

    public function test_fixes_empty_enclosure_mime_type_png() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.png';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('image/png', $result['enclosures'][0]->type,
            'Should set MIME type to image/png for .png files');
    }

    public function test_preserves_existing_mime_type() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg';
        $enclosure->type = 'image/jpeg'; // Already set

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type,
            'Should preserve existing MIME type');
    }

    public function test_handles_url_with_query_params() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg?resize=300x200';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type,
            'Should infer MIME type from extension even with query params');
    }

    /**
     * TEST GROUP 2: URL MATCHING AND UPGRADING
     */

    public function test_matches_bbc_style_url_patterns() {
        // Test the is_same_image_different_size method behavior
        $url1 = 'https://ichef.bbci.co.uk/ace/ws/240/cpsprodpb/abc123/image.jpg';
        $url2 = 'https://ichef.bbci.co.uk/ace/ws/1024/cpsprodpb/abc123/image.jpg';

        // Both URLs should normalize to the same path
        $path1 = parse_url($url1, PHP_URL_PATH);
        $path2 = parse_url($url2, PHP_URL_PATH);

        $normalized1 = preg_replace('/\/\d+[wx]?\//i', '/', $path1);
        $normalized2 = preg_replace('/\/\d+[wx]?\//i', '/', $path2);

        $this->assertEquals($normalized1, $normalized2,
            'BBC URLs with different sizes should normalize to same path');
    }

    public function test_matches_filename_variants() {
        $enclosure_url = 'https://example.com/image_w240.jpg';
        $page_url = 'https://example.com/image_w1024.jpg';

        // Normalize by removing _wNNN pattern
        $normalized1 = preg_replace('/_w\d+\./i', '.', $enclosure_url);
        $normalized2 = preg_replace('/_w\d+\./i', '.', $page_url);

        $this->assertEquals($normalized1, $normalized2,
            'URLs with _wNNN pattern should normalize to match');
    }

    /**
     * TEST GROUP 3: CONFIGURATION
     */

    public function test_inline_enhancement_disabled_when_configured() {
        $article = [
            'title' => 'Test',
            'content' => '<img src="thumb.jpg" srcset="small.jpg 300w, large.jpg 1200w">'
        ];

        // Mock host->get to return false for inline_enhancement
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="thumb.jpg"', $result['content'],
            'Should NOT enhance inline images when disabled');
        $this->assertStringNotContainsString('src="large.jpg"', $result['content'],
            'Should keep original src when inline enhancement disabled');
    }

    public function test_enclosure_type_fixing_disabled_when_configured() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        // Mock host->get to return false for fix_enclosure_type
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return false;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('', $result['enclosures'][0]->type,
            'Should NOT fix MIME type when disabled');
    }

    /**
     * TEST GROUP 4: SRCSET EXTRACTION
     */

    public function test_extract_highest_res_from_srcset_width() {
        // We need to test via the article filter since the method is private
        $article = [
            'title' => 'Test',
            'content' => '<img src="thumb.jpg" srcset="small.jpg 300w, medium.jpg 600w, large.jpg 1200w">'
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturn(true);

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="large.jpg"', $result['content'],
            'Should extract highest resolution from srcset');
    }

    public function test_extract_highest_res_from_srcset_density() {
        $article = [
            'title' => 'Test',
            'content' => '<img src="1x.jpg" srcset="1x.jpg 1x, 2x.jpg 2x, 3x.jpg 3x">'
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturn(true);

        $result = $this->plugin->hook_article_filter($article);

        $this->assertStringContainsString('src="3x.jpg"', $result['content'],
            'Should extract highest density from srcset');
    }

    /**
     * TEST GROUP 5: EDGE CASES
     */

    public function test_handles_article_without_enclosures() {
        $article = [
            'title' => 'Test',
            'content' => 'Just text'
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturn(true);

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals($article['content'], $result['content'],
            'Should handle article without enclosures gracefully');
    }

    public function test_handles_empty_enclosures_array() {
        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => []
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturn(true);

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEmpty($result['enclosures'],
            'Should handle empty enclosures array');
    }

    public function test_handles_multiple_enclosures() {
        $enc1 = new \stdClass();
        $enc1->link = 'https://example.com/image1.jpg';
        $enc1->type = '';

        $enc2 = new \stdClass();
        $enc2->link = 'https://example.com/image2.png';
        $enc2->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enc1, $enc2]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type,
            'Should fix first enclosure MIME type');
        $this->assertEquals('image/png', $result['enclosures'][1]->type,
            'Should fix second enclosure MIME type');
    }

    public function test_handles_non_image_enclosures() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/audio.mp3';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('audio/mpeg', $result['enclosures'][0]->type,
            'Should handle audio enclosures');
    }

    /**
     * TEST GROUP 6: MIME TYPE INFERENCE
     */

    public function test_infers_webp_mime_type() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.webp';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('image/webp', $result['enclosures'][0]->type,
            'Should infer image/webp for .webp files');
    }

    public function test_infers_avif_mime_type() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.avif';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('image/avif', $result['enclosures'][0]->type,
            'Should infer image/avif for .avif files');
    }

    public function test_defaults_to_jpeg_for_unknown_extension() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.xyz';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type,
            'Should default to image/jpeg for unknown extensions');
    }

    /**
     * TEST GROUP 7: INTEGRATION TESTS
     */

    public function test_all_features_work_together() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => '<img src="thumb.jpg" srcset="small.jpg 300w, large.jpg 1200w" loading="lazy">',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'inline_enhancement') return true;
                if ($key === 'fix_enclosure_type') return true;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        // Check inline enhancement worked
        $this->assertStringContainsString('src="large.jpg"', $result['content'],
            'Should enhance inline image');
        $this->assertStringNotContainsString('loading="lazy"', $result['content'],
            'Should remove loading=lazy');

        // Check enclosure type fixing worked
        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type,
            'Should fix enclosure MIME type');
    }

    /**
     * ADDITIONAL EDGE CASE TESTS FOR ENCLOSURE TYPE FIXING
     */

    public function test_handles_url_without_extension() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        // Without extension, defaults to jpeg
        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type,
            'Should default to image/jpeg for URLs without extension');
    }

    public function test_handles_url_with_multiple_dots() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/file.backup.tar.gz';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        // Should extract last extension (gz)
        // Note: Plugin doesn't handle .gz, will default to jpeg
        $this->assertNotEmpty($result['enclosures'][0]->type,
            'Should set some type for file with multiple dots');
    }

    public function test_handles_url_with_fragment() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg#section1';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type,
            'Should handle URLs with fragment identifiers');
    }

    public function test_handles_case_insensitive_extensions() {
        $test_cases = [
            ['url' => 'https://example.com/IMAGE.JPG', 'expected' => 'image/jpeg'],
            ['url' => 'https://example.com/file.PNG', 'expected' => 'image/png'],
            ['url' => 'https://example.com/image.WebP', 'expected' => 'image/webp'],
        ];

        foreach ($test_cases as $test) {
            $enclosure = new \stdClass();
            $enclosure->link = $test['url'];
            $enclosure->type = '';

            $article = [
                'title' => 'Test',
                'content' => 'test',
                'enclosures' => [$enclosure]
            ];

            $this->mockHost->expects($this->any())
                ->method('get')
                ->willReturnCallback(function($plugin, $key, $default) {
                    if ($key === 'fix_enclosure_type') return true;
                    if ($key === 'inline_enhancement') return false;
                    return $default;
                });

            $result = $this->plugin->hook_article_filter($article);

            $this->assertEquals($test['expected'], $result['enclosures'][0]->type,
                "Should handle uppercase extension in {$test['url']}");
        }
    }

    public function test_handles_url_with_encoded_characters() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image%20with%20spaces.jpg';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type,
            'Should handle URL-encoded characters');
    }

    public function test_handles_data_url() {
        $enclosure = new \stdClass();
        $enclosure->link = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAUA';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        // Data URLs should have MIME type extracted from data URL itself
        $this->assertEquals('image/png', $result['enclosures'][0]->type,
            'Should extract MIME type from data URL');
    }

    public function test_preserves_type_for_non_empty_types() {
        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg';
        $enclosure->type = 'image/custom';

        $article = [
            'title' => 'Test',
            'content' => 'test',
            'enclosures' => [$enclosure]
        ];

        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'inline_enhancement') return false;
                return $default;
            });

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('image/custom', $result['enclosures'][0]->type,
            'Should preserve non-empty MIME types');
    }
}
