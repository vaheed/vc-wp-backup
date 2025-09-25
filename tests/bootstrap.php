<?php
// Minimal bootstrap for unit tests (no WP integration here)
require __DIR__ . '/../vendor/autoload.php';

// Provide minimal WordPress shims used by unit-tested classes.
// We only stub what is needed by tests to avoid loading full WP.

if (!isset($GLOBALS['__wp_options'])) {
    $GLOBALS['__wp_options'] = [];
}

if (!function_exists('get_option')) {
    function get_option(string $option, $default = false) {
        return $GLOBALS['__wp_options'][$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option(string $option, $value, bool $autoload = false): bool {
        $GLOBALS['__wp_options'][$option] = $value;
        return true;
    }
}

if (!function_exists('get_bloginfo')) {
    function get_bloginfo($show = '', $filter = 'raw') {
        return '';
    }
}

if (!function_exists('wp_parse_args')) {
    function wp_parse_args($args, $defaults = []) {
        if (is_object($args)) {
            $args = get_object_vars($args);
        }
        if (!is_array($args)) {
            return $defaults;
        }
        return array_replace_recursive($defaults, $args);
    }
}
