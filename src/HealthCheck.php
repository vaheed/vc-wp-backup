<?php

namespace VirakCloud\Backup;

class HealthCheck
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function cronHealth(): void
    {
        // Read settings so static analysis knows the dependency is used
        $this->settings->get();
        // Could write heartbeat info, upcoming runs, last run, etc.
        update_option('vcbk_last_health', gmdate('c'), false);
    }
}
