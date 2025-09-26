<?php

namespace VirakCloud\Backup;

use VirakCloud\Backup\Admin\Admin;

class Plugin
{
    private Settings $settings;
    private Scheduler $scheduler;
    private Logger $logger;
    private HealthCheck $health;

    public function __construct()
    {
        $this->settings = new Settings();
        $this->logger = new Logger();
        $this->scheduler = new Scheduler($this->settings, $this->logger);
        $this->health = new HealthCheck($this->settings);
    }

    public function init(): void
    {
        // Admin UI
        if (is_admin()) {
            (new Admin($this->settings, $this->scheduler, $this->logger))->hooks();
            add_action('admin_enqueue_scripts', [$this, 'enqueueAdminAssets']);
        }

        add_action('init', [$this, 'registerTextdomain']);
        // Register custom cron schedules
        add_filter('cron_schedules', [$this->scheduler, 'registerIntervals']);
        add_action('vcbk_cron_run', [$this, 'runScheduledBackup']);
        add_action('vcbk_cron_health', [$this->health, 'cronHealth']);

        // Ensure our scheduled event exists
        if (!wp_next_scheduled('vcbk_cron_run')) {
            $this->scheduler->scheduleNext();
        }

        // Register REST endpoints for progress/log tail in a future iteration.
    }

    public function registerTextdomain(): void
    {
        load_plugin_textdomain('virakcloud-backup', false, dirname(plugin_basename(VCBK_PLUGIN_FILE)) . '/languages');
    }

    public function runScheduledBackup(): void
    {
        $config = $this->settings->get();
        $bm = new BackupManager($this->settings, $this->logger);
        try {
            $bm->run($config['backup']['type'] ?? 'full', [
                'schedule' => true,
                'encryption' => $config['backup']['encryption'] ?? ['enabled' => false],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('schedule_run_failed', [
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function enqueueAdminAssets(): void
    {
        wp_register_style(
            'vcbk-admin',
            plugins_url('assets/css/admin.css', VCBK_PLUGIN_FILE),
            [],
            VCBK_VERSION
        );
        wp_enqueue_style('vcbk-admin');
        wp_register_script(
            'vcbk-ui',
            plugins_url('assets/js/plugin-ui.js', VCBK_PLUGIN_FILE),
            ['jquery'],
            VCBK_VERSION,
            true
        );
        wp_localize_script('vcbk-ui', 'VCBK', [
            'ajax' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('vcbk_ui'),
        ]);
        wp_enqueue_script('vcbk-ui');
    }
}
