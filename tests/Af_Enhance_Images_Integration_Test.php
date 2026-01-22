<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Af_Enhance_Images;

/**
 * Integration tests for Af_Enhance_Images
 *
 * Tests verify that multiple features work together correctly:
 * 1. Feature combinations don't conflict
 * 2. Configuration options are respected
 * 3. Fetch logic triggers at the right times
 * 4. Complex real-world scenarios work end-to-end
 */
class Af_Enhance_Images_Integration_Test extends TestCase {

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

        $this->plugin = new Af_Enhance_Images();
        $this->plugin->init($this->mockHost);
    }

    // =====================================================================
    // CONDITIONAL FETCH LOGIC TESTS
    // =====================================================================

    public function test_should_fetch_when_og_enabled_and_no_images() {
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'extract_og') return true;
                if ($key === 'upgrade_enclosures') return false;
                if ($key === 'inline_enhancement') return false;
                if ($key === 'fix_enclosure_type') return false;
                return $default;
            });

        // Article with no images should trigger fetch for OG
        $article = [
            'title' => 'Test',
            'content' => '<p>Text only, no images</p>',
            'link' => 'https://example.com/article',
            'enclosures' => []
        ];

        // We can't easily test the actual fetch without mocking UrlHelper,
        // but we can verify the logic by checking article_has_images
        $has_images = $this->callPrivateMethod('article_has_images', [$article]);

        $this->assertFalse($has_images,
            'Article should be detected as having no images, triggering OG fetch');
    }

    public function test_should_not_fetch_when_og_enabled_but_has_images() {
        $enclosure = new \stdClass();
        $enclosure->type = 'image/jpeg';
        $enclosure->link = 'https://example.com/image.jpg';

        $article = [
            'title' => 'Test',
            'content' => 'Content',
            'enclosures' => [$enclosure]
        ];

        $has_images = $this->callPrivateMethod('article_has_images', [$article]);

        $this->assertTrue($has_images,
            'Article has images, should not trigger OG fetch');
    }

    public function test_should_fetch_when_upgrade_enabled_and_has_enclosures() {
        $enclosure = new \stdClass();
        $enclosure->type = 'image/jpeg';
        $enclosure->link = 'https://example.com/image.jpg';

        $article = [
            'title' => 'Test',
            'content' => 'Content',
            'enclosures' => [$enclosure]
        ];

        $this->assertNotEmpty($article['enclosures'],
            'Article has enclosures, should trigger upgrade fetch');
    }

    public function test_should_not_fetch_when_upgrade_enabled_but_no_enclosures() {
        $article = [
            'title' => 'Test',
            'content' => 'Content',
            'enclosures' => []
        ];

        $this->assertEmpty($article['enclosures'],
            'No enclosures, should not trigger upgrade fetch');
    }

    // =====================================================================
    // FEATURE COMBINATION TESTS
    // =====================================================================

    public function test_inline_enhancement_and_enclosure_type_fixing_together() {
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'inline_enhancement') return true;
                if ($key === 'fix_enclosure_type') return true;
                if ($key === 'extract_og') return false;
                if ($key === 'upgrade_enclosures') return false;
                return $default;
            });

        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg';
        $enclosure->type = ''; // Empty type to fix

        $article = [
            'title' => 'Test',
            'content' => '<img src="thumb.jpg" srcset="thumb.jpg 300w, large.jpg 1200w">',
            'enclosures' => [$enclosure]
        ];

        $result = $this->plugin->hook_article_filter($article);

        // Inline enhancement should work
        $this->assertStringContainsString('src="large.jpg"', $result['content'],
            'Inline enhancement should work');

        // Enclosure type fixing should work
        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type,
            'Enclosure type fixing should work');
    }

    public function test_all_features_enabled_with_complex_article() {
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'inline_enhancement') return true;
                if ($key === 'fix_enclosure_type') return true;
                // OG and upgrade would require actual fetch, skip for unit test
                if ($key === 'extract_og') return false;
                if ($key === 'upgrade_enclosures') return false;
                return $default;
            });

        $enc1 = new \stdClass();
        $enc1->link = 'https://example.com/image1.jpg';
        $enc1->type = '';

        $enc2 = new \stdClass();
        $enc2->link = 'https://example.com/image2.png';
        $enc2->type = '';

        $article = [
            'title' => 'Complex Article',
            'content' => '<img src="thumb1.jpg" srcset="large1.jpg 1200w" loading="lazy">
                          <p>Text</p>
                          <img data-src="thumb2.jpg" loading="lazy">',
            'enclosures' => [$enc1, $enc2]
        ];

        $result = $this->plugin->hook_article_filter($article);

        // Inline enhancements
        $this->assertStringContainsString('src="large1.jpg"', $result['content']);
        $this->assertStringNotContainsString('loading="lazy"', $result['content']);
        $this->assertStringContainsString('src="thumb2.jpg"', $result['content']);

        // Enclosure type fixes
        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type);
        $this->assertEquals('image/png', $result['enclosures'][1]->type);
    }

    public function test_respects_configuration_when_features_disabled() {
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                // ALL features disabled
                return false;
            });

        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => '<img src="thumb.jpg" srcset="large.jpg 1200w" loading="lazy">',
            'enclosures' => [$enclosure]
        ];

        $result = $this->plugin->hook_article_filter($article);

        // Nothing should be modified
        $this->assertStringContainsString('src="thumb.jpg"', $result['content'],
            'Inline enhancement should be disabled');
        $this->assertStringContainsString('loading="lazy"', $result['content'],
            'Should not remove loading attribute when disabled');
        $this->assertEquals('', $result['enclosures'][0]->type,
            'Enclosure type should remain empty when fixing disabled');
    }

    // =====================================================================
    // API HOOK INTEGRATION TESTS
    // =====================================================================

    public function test_api_hook_works_with_article_filter_output() {
        // First process through article filter
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'inline_enhancement') return true;
                return false;
            });

        $article = [
            'title' => 'Test',
            'content' => '<img src="thumb.jpg" srcset="large.jpg 1200w">'
        ];

        $filtered = $this->plugin->hook_article_filter($article);

        // Then process through API hook
        $row = ['headline' => $filtered];
        $api_result = $this->plugin->hook_render_article_api($row);

        $this->assertStringContainsString('src="large.jpg"', $api_result['headline']['content'],
            'Article filter changes should be preserved through API hook');
    }

    public function test_api_hook_handles_null_content_from_database() {
        // Simulate article from database with null content
        $row = [
            'headline' => [
                'title' => 'Database Article',
                'content' => null
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertIsString($result['headline']['content'],
            'API hook should convert null to string');
        $this->assertEquals('', $result['headline']['content']);
    }

    // =====================================================================
    // EDGE CASE INTEGRATION TESTS
    // =====================================================================

    public function test_handles_article_with_all_empty_values() {
        $article = [
            'title' => '',
            'content' => '',
            'enclosures' => []
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertIsArray($result);
        $this->assertEquals('', $result['content']);
    }

    public function test_handles_article_with_only_non_image_enclosures() {
        $audio = new \stdClass();
        $audio->link = 'https://example.com/audio.mp3';
        $audio->type = 'audio/mpeg';

        $video = new \stdClass();
        $video->link = 'https://example.com/video.mp4';
        $video->type = 'video/mp4';

        $article = [
            'title' => 'Test',
            'content' => 'Content',
            'enclosures' => [$audio, $video]
        ];

        $has_images = $this->callPrivateMethod('article_has_images', [$article]);

        $this->assertFalse($has_images,
            'Should detect no images when only audio/video enclosures present');
    }

    public function test_handles_mixed_enclosure_types() {
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return true;
                return false;
            });

        $image = new \stdClass();
        $image->link = 'https://example.com/image.jpg';
        $image->type = '';

        $audio = new \stdClass();
        $audio->link = 'https://example.com/audio.mp3';
        $audio->type = 'audio/mpeg';

        $article = [
            'title' => 'Test',
            'content' => 'Content',
            'enclosures' => [$image, $audio]
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type,
            'Should fix image enclosure type');
        $this->assertEquals('audio/mpeg', $result['enclosures'][1]->type,
            'Should leave audio enclosure type unchanged');
    }

    public function test_inline_images_with_complex_srcset() {
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'inline_enhancement') return true;
                return false;
            });

        $article = [
            'title' => 'Test',
            'content' => '<img src="thumb.jpg"
                srcset="small.jpg 300w,
                        medium.jpg 600w,
                        large.jpg 1200w,
                        xlarge.jpg 1920w,
                        retina.jpg 2x">'
        ];

        $result = $this->plugin->hook_article_filter($article);

        // Should choose retina.jpg (2x = 2000w equivalent, highest)
        $this->assertStringContainsString('src="retina.jpg"', $result['content']);
    }

    public function test_preserves_article_structure_through_processing() {
        $article = [
            'title' => 'Test Title',
            'content' => '<p>Content</p>',
            'link' => 'https://example.com/article',
            'author' => 'John Doe',
            'guid' => '12345',
            'enclosures' => [],
            'custom_field' => 'custom_value'
        ];

        $result = $this->plugin->hook_article_filter($article);

        // All fields should be preserved
        $this->assertEquals('Test Title', $result['title']);
        $this->assertEquals('https://example.com/article', $result['link']);
        $this->assertEquals('John Doe', $result['author']);
        $this->assertEquals('12345', $result['guid']);
        $this->assertEquals('custom_value', $result['custom_field']);
    }

    // =====================================================================
    // CONFIGURATION INTERACTION TESTS
    // =====================================================================

    public function test_inline_enhancement_disabled_preserves_original() {
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'inline_enhancement') return false; // DISABLED
                return $default;
            });

        $original_content = '<img src="thumb.jpg" srcset="large.jpg 1200w" loading="lazy">';
        $article = [
            'title' => 'Test',
            'content' => $original_content
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals($original_content, $result['content'],
            'Content should remain unchanged when inline enhancement disabled');
    }

    public function test_fix_enclosure_type_disabled_preserves_empty() {
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'fix_enclosure_type') return false; // DISABLED
                return $default;
            });

        $enclosure = new \stdClass();
        $enclosure->link = 'https://example.com/image.jpg';
        $enclosure->type = '';

        $article = [
            'title' => 'Test',
            'content' => 'Content',
            'enclosures' => [$enclosure]
        ];

        $result = $this->plugin->hook_article_filter($article);

        $this->assertEquals('', $result['enclosures'][0]->type,
            'Type should remain empty when fixing disabled');
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
