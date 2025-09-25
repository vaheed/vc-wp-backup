<?php
namespace VirakCloud\Backup;

class Scheduler
{
    private Settings $settings;
    private Logger $logger;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
        add_action('update_option_vcbk_settings', [$this, 'reschedule'], 10, 3);
    }

    public function register_intervals(array $schedules): array
    {
        $schedules['2h'] = ['interval' => 2 * HOUR_IN_SECONDS, 'display' => __('Every 2 hours', 'virakcloud-backup')];
        $schedules['4h'] = ['interval' => 4 * HOUR_IN_SECONDS, 'display' => __('Every 4 hours', 'virakcloud-backup')];
        $schedules['8h'] = ['interval' => 8 * HOUR_IN_SECONDS, 'display' => __('Every 8 hours', 'virakcloud-backup')];
        $schedules['12h'] = ['interval' => 12 * HOUR_IN_SECONDS, 'display' => __('Every 12 hours', 'virakcloud-backup')];
        $schedules['fortnightly'] = ['interval' => 14 * DAY_IN_SECONDS, 'display' => __('Every 2 weeks', 'virakcloud-backup')];
        $schedules['monthly'] = ['interval' => 30 * DAY_IN_SECONDS, 'display' => __('Every month', 'virakcloud-backup')];
        return $schedules;
    }

    public function reschedule($old, $value, $option): void
    {
        if ($option !== 'vcbk_settings') {
            return;
        }
        $this->unschedule();
        $this->schedule_next();
    }

    public function schedule_next(): void
    {
        $cfg = $this->settings->get();
        $interval = $cfg['schedule']['interval'] ?? 'weekly';
        $start = $cfg['schedule']['start_time'] ?? '01:30';
        // Compute next occurrence in site timezone
        $tz = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        [$h, $m] = array_map('intval', explode(':', $start));
        $next = $now->setTime($h, $m, 0);
        if ($next <= $now) {
            $next = $next->modify('+1 day');
        }
        // Normalize monthly/weekly
        if ($interval === 'weekly') {
            // Keep next as computed; WP-Cron uses recurrence
        } elseif ($interval === 'monthly') {
            // Next month same day/time
        }
        wp_schedule_event($next->getTimestamp(), $interval, 'vcbk_cron_run');
        $this->logger->info('scheduled', ['at' => $next->format(DATE_ATOM), 'interval' => $interval]);
    }

    public function unschedule(): void
    {
        $timestamp = wp_next_scheduled('vcbk_cron_run');
        while ($timestamp) {
            wp_unschedule_event($timestamp, 'vcbk_cron_run');
            $timestamp = wp_next_scheduled('vcbk_cron_run');
        }
    }
}

