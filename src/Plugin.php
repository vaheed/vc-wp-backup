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
        }

        add_action('init', [$this, 'register_textdomain']);
        add_action('cron_schedules', [$this->scheduler, 'register_intervals']);
        add_action('vcbk_cron_run', [$this, 'run_scheduled_backup']);
        add_action('vcbk_cron_health', [$this->health, 'cron_health']);

        // Register REST endpoints for progress/log tail in a future iteration.
    }

    public function register_textdomain(): void
    {
        // Already loaded from main file; kept for safety
    }

    public function run_scheduled_backup(): void
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
}

