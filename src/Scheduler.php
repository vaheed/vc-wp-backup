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

    /**
     * @param array<string, array{interval:int, display:string}> $schedules
     * @return array<string, array{interval:int, display:string}>
     */
    public function registerIntervals(array $schedules): array
    {
        $schedules['2h'] = [
            'interval' => 2 * HOUR_IN_SECONDS,
            'display' => __('Every 2 hours', 'virakcloud-backup'),
        ];
        $schedules['4h'] = [
            'interval' => 4 * HOUR_IN_SECONDS,
            'display' => __('Every 4 hours', 'virakcloud-backup'),
        ];
        $schedules['8h'] = [
            'interval' => 8 * HOUR_IN_SECONDS,
            'display' => __('Every 8 hours', 'virakcloud-backup'),
        ];
        $schedules['12h'] = [
            'interval' => 12 * HOUR_IN_SECONDS,
            'display' => __('Every 12 hours', 'virakcloud-backup'),
        ];
        $schedules['fortnightly'] = [
            'interval' => 14 * DAY_IN_SECONDS,
            'display' => __('Every 2 weeks', 'virakcloud-backup'),
        ];
        $schedules['monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display' => __('Every month', 'virakcloud-backup'),
        ];
        return $schedules;
    }

    /**
     * @param mixed $old
     * @param mixed $value
     * @param string $option
     */
    public function reschedule(mixed $old, mixed $value, string $option): void
    {
        if ($option !== 'vcbk_settings') {
            return;
        }
        $this->unschedule();
        $this->scheduleNext();
    }

    public function scheduleNext(): void
    {
        $cfg = $this->settings->get();
        $interval = (string) ($cfg['schedule']['interval'] ?? 'weekly');
        $start = (string) ($cfg['schedule']['start_time'] ?? '01:30');

        $tz = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        [$h, $m] = [1, 30];
        if (preg_match('/^(\d{2}):(\d{2})$/', $start, $mm)) {
            $h = (int) $mm[1];
            $m = (int) $mm[2];
        }

        // Determine first run time
        $next = $now;
        $short = ['2h' => 2, '4h' => 4, '8h' => 8, '12h' => 12];
        if (isset($short[$interval])) {
            $next = $now->modify('+' . $short[$interval] . ' hours');
        } else {
            $first = $now->setTime($h, $m, 0);
            if ($first <= $now) {
                $first = $first->modify('+1 day');
            }
            if ($interval === 'weekly') {
                // keep computed; recur weekly
                $next = $first;
            } elseif ($interval === 'fortnightly') {
                $next = $first; // recur every 14 days
            } elseif ($interval === 'monthly') {
                $next = $first; // recur every ~30 days via custom schedule
            } else { // daily
                $next = $first;
                $interval = 'daily';
            }
        }

        // Schedule the event if not already present
        wp_schedule_event($next->getTimestamp(), $interval, 'vcbk_cron_run');
        $this->logger->info('scheduled', [
            'at' => $next->format(DATE_ATOM),
            'interval' => $interval,
        ]);
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
