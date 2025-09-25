<?php
use PHPUnit\Framework\TestCase;
use VirakCloud\Backup\Settings;

final class SettingsTest extends TestCase
{
    public function testDefaults(): void
    {
        $s = new Settings();
        $cfg = $s->get();
        $this->assertArrayHasKey('s3', $cfg);
        $this->assertArrayHasKey('backup', $cfg);
        $this->assertArrayHasKey('schedule', $cfg);
    }
}

