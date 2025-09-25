<?php

/**
 * Plugin Name: VirakCloud Backup & Migrate
 * Plugin URI: https://virakcloud.com
 * Description: Backup, restore, and migrate WordPress to VirakCloud S3-compatible storage with scheduled backups.
 * Version: 0.1.3
 * Author: VirakCloud
 * Author URI: https://virakcloud.com
 * Text Domain: virakcloud-backup
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

// Basic constants
define('VCBK_VERSION', '0.1.3');
define('VCBK_PLUGIN_FILE', __FILE__);
define('VCBK_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VCBK_PLUGIN_URL', plugin_dir_url(__FILE__));

// Composer autoload (optional during dev)
$vcbk_autoload = VCBK_PLUGIN_DIR . 'vendor/autoload.php';
if (file_exists($vcbk_autoload)) {
    require_once $vcbk_autoload;
}

// Minimal fallback autoloader if composer not installed yet (dev convenience)
spl_autoload_register(function ($class) {
    if (strpos($class, 'VirakCloud\\Backup') !== 0) {
        return;
    }
    $rel = str_replace('VirakCloud\\Backup', 'src', $class);
    $path = VCBK_PLUGIN_DIR . str_replace('\\', '/', $rel) . '.php';
    if (file_exists($path)) {
        require_once $path;
    }
});

// Show admin notice if composer deps missing when needed
function vcbk_admin_notice_missing_vendor()
{
    if (!current_user_can('activate_plugins')) {
        return;
    }
    $msg = __(
        'VirakCloud Backup requires Composer dependencies. Please run composer install inside the plugin folder.',
        'virakcloud-backup'
    );
    echo '<div class="notice notice-warning"><p>' . esc_html($msg) . '</p></div>';
}

// Boot plugin
add_action('plugins_loaded', function () {
    // Load translations
    load_plugin_textdomain('virakcloud-backup', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Instantiate main plugin
    try {
        $plugin = new \VirakCloud\Backup\Plugin();
        $plugin->init();
        // Make instance globally available if needed
        $GLOBALS['vcbk_plugin'] = $plugin;
    } catch (Throwable $e) {
        if (is_admin()) {
            add_action('admin_notices', function () use ($e) {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($e->getMessage()));
            });
        }
    }
});

// Add helpful links on the plugins list row (Website & Documentation)
add_filter('plugin_row_meta', function (array $links, string $file) {
    if ($file === plugin_basename(__FILE__)) {
        $links[] = '<a href="https://virakcloud.com" target="_blank" rel="noopener noreferrer">'
            . esc_html__('Website', 'virakcloud-backup')
            . '</a>';
        $links[] = '<a href="https://docs.virakcloud.com" target="_blank" rel="noopener noreferrer">'
            . esc_html__('Documentation', 'virakcloud-backup')
            . '</a>';
    }
    return $links;
}, 10, 2);

register_activation_hook(__FILE__, function () {
    if (!function_exists('openssl_open') && !function_exists('sodium_crypto_secretbox')) {
        // Not fatal, but warn: encryption may be unavailable
        add_option('vcbk_activation_warning', 'missing_crypto');
    }
    // Schedule health event if not present
    if (!wp_next_scheduled('vcbk_cron_health')) {
        wp_schedule_event(time() + 300, 'hourly', 'vcbk_cron_health');
    }
});

register_deactivation_hook(__FILE__, function () {
    // Clear scheduled events
    wp_clear_scheduled_hook('vcbk_cron_run');
    wp_clear_scheduled_hook('vcbk_cron_health');
});

// WP-CLI commands
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('vcbk', [\VirakCloud\Backup\CliCommands::class, 'root']);
}
