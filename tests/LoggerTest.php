<?php

use PHPUnit\Framework\TestCase;
use VirakCloud\Backup\Logger;

final class LoggerTest extends TestCase
{
    protected function setUp(): void
    {
        // Ensure uploads dir exists for Logger
        if (!function_exists('wp_get_upload_dir')) {
            function wp_get_upload_dir() {
                $base = sys_get_temp_dir() . '/vcbk-tests';
                if (!is_dir($base)) {
                    mkdir($base, 0777, true);
                }
                return ['basedir' => $base, 'baseurl' => 'http://example.test'];
            }
        }
        if (!function_exists('wp_mkdir_p')) {
            function wp_mkdir_p($dir) { return is_dir($dir) ?: mkdir($dir, 0777, true); }
        }
        if (!function_exists('wp_json_encode')) {
            function wp_json_encode($data, $options = 0) { return json_encode($data, $options); }
        }
        if (!function_exists('trailingslashit')) {
            function trailingslashit($path){ return rtrim($path, '/\\') . '/'; }
        }
        if (!function_exists('update_option')) {
            function update_option($k,$v){ $GLOBALS['__wp_options'][$k] = $v; return true; }
        }
        if (!function_exists('get_option')) {
            function get_option($k,$d=null){ return $GLOBALS['__wp_options'][$k] ?? $d; }
        }
    }

    public function testWriteAndTail(): void
    {
        $logger = new Logger();
        $logger->info('unit_test', ['foo' => 'bar']);
        $lines = $logger->tail(10);
        $this->assertNotEmpty($lines);
        $this->assertStringContainsString('unit_test', implode("\n", $lines));
    }

    public function testProgressRoundtrip(): void
    {
        $logger = new Logger();
        $logger->setProgress(42, 'Testing');
        $p = $logger->getProgress();
        $this->assertIsArray($p);
        $this->assertSame(42, $p['percent']);
        $this->assertSame('Testing', $p['stage']);
    }
}

