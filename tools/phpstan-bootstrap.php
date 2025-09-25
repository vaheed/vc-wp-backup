<?php

// Lightweight bootstrap for PHPStan to satisfy WordPress globals/constants

// Common WP constants used in the codebase
defined('ABSPATH') || define('ABSPATH', '/var/www/html/');
defined('WP_CONTENT_DIR') || define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
defined('HOUR_IN_SECONDS') || define('HOUR_IN_SECONDS', 3600);
defined('DAY_IN_SECONDS') || define('DAY_IN_SECONDS', 86400);
defined('ARRAY_A') || define('ARRAY_A', 'ARRAY_A');

// Minimal WP-CLI shim to keep static calls analyzable
if (!class_exists('WP_CLI')) {
    class WP_CLI
    {
        public static function log(string $message): void {}
        public static function success(string $message): void {}
        public static function error(string $message): void {}
    }
}

