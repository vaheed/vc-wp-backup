<?php
namespace VirakCloud\Backup;

class HealthCheck
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function cron_health(): void
    {
        // Could write heartbeat info, upcoming runs, last run, etc.
        update_option('vcbk_last_health', gmdate('c'), false);
    }
}

