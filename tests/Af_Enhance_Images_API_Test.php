<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Af_Enhance_Images;

/**
 * Test suite for Af_Enhance_Images hook_render_article_api
 *
 * Tests verify that the plugin correctly handles edge cases in API rendering,
 * particularly null/missing content that can cause TypeError in DiskCache::rewrite_urls()
 *
 * These tests would have caught the production bug where articles with null content
 * caused: TypeError: DiskCache::rewrite_urls(): Argument #1 ($str) must be of type string, null given
 */
class Af_Enhance_Images_API_Test extends TestCase {

    private $plugin;
    private $mockHost;

    protected function setUp(): void {
        // Mock the PluginHost
        $this->mockHost = $this->createMock(\PluginHost::class);

        // Suppress the host->add_hook call during init
        $this->mockHost->expects($this->any())
            ->method('add_hook')
            ->willReturn(true);

        // Mock host->get to return defaults
        $this->mockHost->expects($this->any())
            ->method('get')
            ->willReturnCallback(function($plugin, $key, $default) {
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
     * CRITICAL TEST: Null content in headline structure
     *
     * This is the exact scenario that caused production TypeError.
     * Articles in database with content = null would crash the API.
     */
    public function test_null_content_in_headline_converted_to_empty_string() {
        $row = [
            'headline' => [
                'title' => 'Justified True Belief',
                'content' => null  // NULL CONTENT - production bug scenario
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertIsString($result['headline']['content'],
            'Content must be string to prevent TypeError in DiskCache::rewrite_urls()');

        $this->assertEquals('', $result['headline']['content'],
            'Null content should be converted to empty string');
    }

    /**
     * CRITICAL TEST: Null content in article structure
     */
    public function test_null_content_in_article_converted_to_empty_string() {
        $row = [
            'article' => [
                'title' => 'Test Article',
                'content' => null
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertIsString($result['article']['content'],
            'Content must be string type');
        $this->assertEquals('', $result['article']['content'],
            'Null content should be converted to empty string');
    }

    /**
     * CRITICAL TEST: Missing content field entirely
     *
     * Some articles may not have content field at all.
     */
    public function test_missing_content_field_in_headline_is_added() {
        $row = [
            'headline' => [
                'title' => 'Test Article'
                // NO content field
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertArrayHasKey('content', $result['headline'],
            'Content field must exist');
        $this->assertIsString($result['headline']['content'],
            'Content must be string type');
        $this->assertEquals('', $result['headline']['content'],
            'Missing content should be added as empty string');
    }

    /**
     * Test empty string content is preserved (not null)
     */
    public function test_empty_string_content_is_preserved() {
        $row = [
            'headline' => [
                'title' => 'Test Article',
                'content' => ''  // Empty string is valid
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertSame('', $result['headline']['content'],
            'Empty string content should be preserved as-is');
    }

    /**
     * Test normal content is unchanged
     */
    public function test_normal_content_unchanged() {
        $content = '<p>Normal article content</p>';
        $row = [
            'headline' => [
                'title' => 'Test Article',
                'content' => $content
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertEquals($content, $result['headline']['content'],
            'Normal content should pass through unchanged');
    }

    /**
     * Test content with string "0" (edge case - falsy but valid)
     */
    public function test_string_zero_content_is_preserved() {
        $row = [
            'headline' => [
                'title' => 'Test Article',
                'content' => '0'
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertSame('0', $result['headline']['content'],
            'String "0" is valid content and should be preserved');
    }

    /**
     * Test null article data returns unchanged
     */
    public function test_null_article_data_returns_unchanged() {
        $row = [
            'article' => null
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertEquals($row, $result,
            'Null article should return unchanged without errors');
    }

    /**
     * Test empty row returns unchanged
     */
    public function test_empty_row_returns_unchanged() {
        $row = [];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertEquals($row, $result,
            'Empty row should return unchanged');
    }

    /**
     * Test both headline and article present (headline takes precedence)
     */
    public function test_headline_takes_precedence_when_both_present() {
        $row = [
            'headline' => [
                'title' => 'Headline',
                'content' => null  // This should be fixed
            ],
            'article' => [
                'title' => 'Article',
                'content' => '<p>Article content</p>'  // This should be unchanged
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        // Headline should be processed
        $this->assertEquals('', $result['headline']['content'],
            'Headline null content should be fixed');

        // Article should be unchanged
        $this->assertEquals('<p>Article content</p>', $result['article']['content'],
            'Article content should remain unchanged when headline is processed');
    }

    /**
     * INTEGRATION TEST: Simulates what DiskCache::rewrite_urls() expects
     *
     * This test verifies the plugin prevents the TypeError that occurs in production.
     */
    public function test_content_is_always_string_for_diskcache() {
        $test_cases = [
            ['headline' => ['title' => 'Test', 'content' => null]],
            ['headline' => ['title' => 'Test']],  // Missing content
            ['article' => ['title' => 'Test', 'content' => null]],
            ['article' => ['title' => 'Test']],
        ];

        foreach ($test_cases as $index => $row) {
            $result = $this->plugin->hook_render_article_api($row);

            // Extract content based on structure
            if (isset($result['headline'])) {
                $content = $result['headline']['content'] ?? null;
            } elseif (isset($result['article'])) {
                $content = $result['article']['content'] ?? null;
            } else {
                continue; // Empty row, skip
            }

            // CRITICAL: Content must be string for DiskCache::rewrite_urls()
            $this->assertIsString($content,
                "Test case $index: Content must be string type to prevent TypeError in DiskCache::rewrite_urls()");

            // Additional check: is_string() must return true
            $this->assertTrue(is_string($content),
                "Test case $index: is_string() must return true");
        }
    }

    /**
     * Test missing content in article structure
     */
    public function test_missing_content_field_in_article_is_added() {
        $row = [
            'article' => [
                'title' => 'Test Article'
                // NO content field
            ]
        ];

        $result = $this->plugin->hook_render_article_api($row);

        $this->assertArrayHasKey('content', $result['article'],
            'Content field must exist in article');
        $this->assertIsString($result['article']['content'],
            'Content must be string type');
        $this->assertEquals('', $result['article']['content'],
            'Missing content should be added as empty string');
    }
}
