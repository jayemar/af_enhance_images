<?php
/**
 * Bootstrap file for PHPUnit tests
 * Provides stub classes for TT-RSS dependencies
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Stub PluginHost class
class PluginHost {
    const HOOK_ARTICLE_FILTER = 1;
    const HOOK_RENDER_ARTICLE_API = 2;
    const HOOK_ENCLOSURE_IMPORTED = 3;
    const HOOK_PREFS_TAB = 4;

    public $pdo;

    public function add_hook($hook, $plugin) {
        return true;
    }

    public function get($plugin, $key, $default = null) {
        return $default;
    }

    public function set($plugin, $key, $value) {
        return true;
    }
}

// Stub Plugin class
class Plugin {
    public function api_version() {
        return 2;
    }
}

// Stub Debug class
class Debug {
    const LOG_VERBOSE = 1;
    const LOG_NORMAL = 0;

    static function log($msg, $level = 0) {
        // No-op for testing
    }
}

// Load the plugin class
require_once __DIR__ . '/../init.php';
