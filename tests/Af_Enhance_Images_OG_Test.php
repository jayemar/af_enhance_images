<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Af_Enhance_Images;

/**
 * Test suite for Open Graph metadata extraction and application
 *
 * Tests verify that the plugin correctly:
 * 1. Extracts Open Graph metadata from HTML
 * 2. Falls back to Twitter Card metadata
 * 3. Applies OG data to articles (images, author, description)
 * 4. Handles edge cases and malformed HTML
 */
class Af_Enhance_Images_OG_Test extends TestCase {

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
    // OPEN GRAPH EXTRACTION TESTS
    // =====================================================================

    public function test_extracts_og_image_from_meta_tags() {
        $html = '<html><head>
            <meta property="og:image" content="https://example.com/image.jpg">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result, 'Should extract OG metadata');
        $this->assertEquals('https://example.com/image.jpg', $result['image']);
    }

    public function test_extracts_og_description() {
        $html = '<html><head>
            <meta property="og:description" content="This is a test description">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('This is a test description', $result['description']);
    }

    public function test_extracts_og_article_author() {
        $html = '<html><head>
            <meta property="og:article:author" content="John Doe">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('John Doe', $result['author']);
    }

    public function test_extracts_article_author_without_og_prefix() {
        $html = '<html><head>
            <meta property="article:author" content="Jane Smith">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('Jane Smith', $result['author']);
    }

    public function test_extracts_multiple_article_tags() {
        $html = '<html><head>
            <meta property="og:image" content="https://example.com/image.jpg">
            <meta property="article:tag" content="Technology">
            <meta property="article:tag" content="Programming">
            <meta property="article:tag" content="PHP">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result, 'Need image/description/author for non-null result');
        $this->assertCount(3, $result['tags']);
        $this->assertContains('Technology', $result['tags']);
        $this->assertContains('Programming', $result['tags']);
        $this->assertContains('PHP', $result['tags']);
    }

    public function test_extracts_og_image_dimensions() {
        $html = '<html><head>
            <meta property="og:image" content="https://example.com/image.jpg">
            <meta property="og:image:width" content="1200">
            <meta property="og:image:height" content="630">
            <meta property="og:image:alt" content="Test image">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('https://example.com/image.jpg', $result['image']);
        $this->assertEquals(1200, $result['image_width']);
        $this->assertEquals(630, $result['image_height']);
        $this->assertEquals('Test image', $result['image_alt']);
    }

    public function test_extracts_og_metadata_complex() {
        $html = '<html><head>
            <meta property="og:title" content="Article Title">
            <meta property="og:description" content="Article description">
            <meta property="og:image" content="https://example.com/image.jpg">
            <meta property="og:type" content="article">
            <meta property="og:site_name" content="Example Site">
            <meta property="article:published_time" content="2026-01-22T10:00:00Z">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('Article Title', $result['title']);
        $this->assertEquals('Article description', $result['description']);
        $this->assertEquals('https://example.com/image.jpg', $result['image']);
        $this->assertEquals('article', $result['type']);
        $this->assertEquals('Example Site', $result['site_name']);
        $this->assertEquals('2026-01-22T10:00:00Z', $result['published_time']);
    }

    // =====================================================================
    // TWITTER CARD FALLBACK TESTS
    // =====================================================================

    public function test_falls_back_to_twitter_image_when_og_missing() {
        $html = '<html><head>
            <meta name="twitter:image" content="https://example.com/twitter-image.jpg">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('https://example.com/twitter-image.jpg', $result['image'],
            'Should fall back to twitter:image when og:image missing');
    }

    public function test_og_image_takes_precedence_over_twitter() {
        $html = '<html><head>
            <meta property="og:image" content="https://example.com/og-image.jpg">
            <meta name="twitter:image" content="https://example.com/twitter-image.jpg">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('https://example.com/og-image.jpg', $result['image'],
            'og:image should take precedence over twitter:image');
    }

    public function test_falls_back_to_twitter_description() {
        $html = '<html><head>
            <meta name="twitter:description" content="Twitter description">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('Twitter description', $result['description']);
    }

    public function test_falls_back_to_twitter_creator() {
        $html = '<html><head>
            <meta name="twitter:creator" content="@johndoe">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('@johndoe', $result['author']);
    }

    public function test_falls_back_to_twitter_site() {
        $html = '<html><head>
            <meta name="twitter:site" content="@example">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('@example', $result['author']);
    }

    // =====================================================================
    // HTML HANDLING TESTS
    // =====================================================================

    public function test_handles_html_entities_in_content() {
        $html = '<html><head>
            <meta property="og:title" content="Test &amp; Title with &quot;quotes&quot;">
            <meta property="og:description" content="Description with &lt;tags&gt;">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('Test & Title with "quotes"', $result['title'],
            'Should decode HTML entities');
        $this->assertEquals('Description with <tags>', $result['description']);
    }

    public function test_ignores_meta_tags_with_empty_content() {
        $html = '<html><head>
            <meta property="og:image" content="">
            <meta property="og:description" content="">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNull($result, 'Should return null when all content is empty');
    }

    public function test_handles_malformed_meta_tags() {
        $html = '<html><head>
            <meta property="og:image">
            <meta content="value without property">
            <meta property="og:description" content="Valid description">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertNull($result['image'], 'Should skip meta tag without content');
        $this->assertEquals('Valid description', $result['description']);
    }

    public function test_returns_null_when_no_useful_metadata() {
        $html = '<html><head>
            <meta property="og:type" content="website">
            <meta property="og:site_name" content="Example">
        </head></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNull($result,
            'Should return null when no image, description, or author found');
    }

    public function test_handles_html_without_head_tag() {
        $html = '<html>
            <meta property="og:image" content="https://example.com/image.jpg">
        </html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result,
            'Should still extract metadata without proper head tag');
        $this->assertEquals('https://example.com/image.jpg', $result['image']);
    }

    public function test_only_parses_head_section() {
        $html = '<html><head>
            <meta property="og:image" content="https://example.com/head-image.jpg">
        </head><body>
            <meta property="og:image" content="https://example.com/body-image.jpg">
        </body></html>';

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('https://example.com/head-image.jpg', $result['image'],
            'Should only parse meta tags in head section');
    }

    public function test_handles_single_quotes_in_attributes() {
        $html = "<html><head>
            <meta property='og:image' content='https://example.com/image.jpg'>
        </head></html>";

        $result = $this->callPrivateMethod('extract_og_metadata', [$html]);

        $this->assertNotNull($result);
        $this->assertEquals('https://example.com/image.jpg', $result['image']);
    }

    // =====================================================================
    // OPEN GRAPH APPLICATION TESTS
    // =====================================================================

    public function test_adds_og_image_as_enclosure_when_no_images() {
        $article = [
            'title' => 'Test',
            'content' => 'Content',
            'enclosures' => []
        ];

        $og_data = [
            'image' => 'https://example.com/og-image.jpg',
            'image_alt' => 'OG Image',
            'description' => null,
            'author' => null,
            'tags' => []
        ];

        $result = $this->callPrivateMethod('apply_og_metadata', [$article, $og_data]);

        $this->assertCount(1, $result['enclosures']);
        $this->assertEquals('https://example.com/og-image.jpg', $result['enclosures'][0]->link);
        $this->assertEquals('image/jpeg', $result['enclosures'][0]->type);
        $this->assertEquals('OG Image', $result['enclosures'][0]->title);
    }

    public function test_does_not_add_og_image_when_article_has_image_enclosures() {
        $existing = new \stdClass();
        $existing->link = 'https://example.com/existing.jpg';
        $existing->type = 'image/png';

        $article = [
            'title' => 'Test',
            'content' => 'Content',
            'enclosures' => [$existing]
        ];

        $og_data = [
            'image' => 'https://example.com/og-image.jpg',
            'description' => null,
            'author' => null,
            'tags' => []
        ];

        $result = $this->callPrivateMethod('apply_og_metadata', [$article, $og_data]);

        $this->assertCount(1, $result['enclosures'],
            'Should not add OG image when article already has image enclosure');
        $this->assertEquals('https://example.com/existing.jpg', $result['enclosures'][0]->link);
    }

    public function test_sets_author_from_og_article_author() {
        $article = [
            'title' => 'Test',
            'content' => 'Content',
            'author' => ''
        ];

        $og_data = [
            'image' => null,
            'description' => null,
            'author' => 'John Doe',
            'tags' => []
        ];

        $result = $this->callPrivateMethod('apply_og_metadata', [$article, $og_data]);

        $this->assertEquals('John Doe', $result['author']);
    }

    public function test_does_not_overwrite_existing_author() {
        $article = [
            'title' => 'Test',
            'content' => 'Content',
            'author' => 'Existing Author'
        ];

        $og_data = [
            'image' => null,
            'description' => null,
            'author' => 'OG Author',
            'tags' => []
        ];

        $result = $this->callPrivateMethod('apply_og_metadata', [$article, $og_data]);

        $this->assertEquals('Existing Author', $result['author'],
            'Should not overwrite existing author');
    }

    public function test_creates_enclosures_array_if_missing() {
        $article = [
            'title' => 'Test',
            'content' => 'Content',
            'enclosures' => []  // Plugin expects enclosures to exist
        ];

        $og_data = [
            'image' => 'https://example.com/og-image.jpg',
            'description' => null,
            'author' => null,
            'tags' => []
        ];

        $result = $this->callPrivateMethod('apply_og_metadata', [$article, $og_data]);

        $this->assertArrayHasKey('enclosures', $result);
        $this->assertIsArray($result['enclosures']);
        $this->assertCount(1, $result['enclosures']);
    }

    public function test_infers_mime_type_for_og_image() {
        $test_cases = [
            ['url' => 'https://example.com/image.png', 'expected' => 'image/png'],
            ['url' => 'https://example.com/image.webp', 'expected' => 'image/webp'],
            ['url' => 'https://example.com/image.gif', 'expected' => 'image/gif'],
            ['url' => 'https://example.com/image.jpg', 'expected' => 'image/jpeg'],
        ];

        foreach ($test_cases as $test) {
            $article = ['title' => 'Test', 'content' => 'Content', 'enclosures' => []];
            $og_data = [
                'image' => $test['url'],
                'description' => null,
                'author' => null,
                'tags' => []
            ];

            $result = $this->callPrivateMethod('apply_og_metadata', [$article, $og_data]);

            $this->assertEquals($test['expected'], $result['enclosures'][0]->type,
                "Should infer correct MIME type for {$test['url']}");
        }
    }

    // =====================================================================
    // CONTENT ENHANCEMENT TESTS
    // =====================================================================

    public function test_prepends_og_description_when_content_shorter() {
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'enhance_content') return true;
                return $default;
            });

        $article = [
            'title' => 'Test',
            'content' => 'Short',
            'enclosures' => []
        ];

        $og_data = [
            'image' => null,
            'description' => 'This is a much longer description from Open Graph metadata',
            'author' => null,
            'tags' => []
        ];

        $result = $this->callPrivateMethod('apply_og_metadata', [$article, $og_data]);

        $this->assertStringContainsString('This is a much longer description', $result['content']);
        $this->assertStringContainsString('<hr>', $result['content']);
        $this->assertStringContainsString('Short', $result['content']);
    }

    public function test_does_not_modify_when_content_longer() {
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'enhance_content') return true;
                return $default;
            });

        $article = [
            'title' => 'Test',
            'content' => 'This is a much longer article content that should not be replaced',
            'enclosures' => []
        ];

        $og_data = [
            'image' => null,
            'description' => 'Short OG',
            'author' => null,
            'tags' => []
        ];

        $result = $this->callPrivateMethod('apply_og_metadata', [$article, $og_data]);

        $this->assertStringNotContainsString('Short OG', $result['content'],
            'Should not add OG description when existing content is longer');
    }

    public function test_handles_empty_original_content() {
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'enhance_content') return true;
                return $default;
            });

        $article = [
            'title' => 'Test',
            'content' => '',
            'enclosures' => []
        ];

        $og_data = [
            'image' => null,
            'description' => 'OG Description',
            'author' => null,
            'tags' => []
        ];

        $result = $this->callPrivateMethod('apply_og_metadata', [$article, $og_data]);

        $this->assertStringContainsString('OG Description', $result['content']);
    }

    public function test_respects_enhance_content_configuration_disabled() {
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
                if ($key === 'enhance_content') return false; // DISABLED
                return $default;
            });

        $article = [
            'title' => 'Test',
            'content' => 'Short',
            'enclosures' => []
        ];

        $og_data = [
            'image' => null,
            'description' => 'This is a much longer description',
            'author' => null,
            'tags' => []
        ];

        $result = $this->callPrivateMethod('apply_og_metadata', [$article, $og_data]);

        $this->assertStringNotContainsString('much longer description', $result['content'],
            'Should not enhance content when feature disabled');
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
