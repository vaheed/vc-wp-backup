<?php
namespace VirakCloud\Backup;

class Logger
{
    private string $logDir;
    private string $logFile;

    public function __construct()
    {
        $upload = wp_get_upload_dir();
        $this->logDir = trailingslashit($upload['basedir']) . 'virakcloud-backup/logs';
        if (!is_dir($this->logDir)) {
            wp_mkdir_p($this->logDir);
        }
        $this->logFile = $this->logDir . '/vcbk.log';
    }

    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    public function debug(string $event, array $context = []): void
    {
        $this->write('debug', $event, $context);
    }

    private function write(string $level, string $event, array $context): void
    {
        $entry = [
            'ts' => gmdate('c'),
            'level' => $level,
            'event' => $event,
            'context' => $this->scrub($context),
        ];
        $line = wp_json_encode($entry) . "\n";
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    private function scrub(array $context): array
    {
        // Remove secrets from logs
        $keys = ['access_key', 'secret_key', 'Authorization', 'password', 'token'];
        array_walk_recursive($context, function (&$value, $key) use ($keys) {
            if (in_array((string) $key, $keys, true)) {
                $value = '***redacted***';
            }
        });
        return $context;
    }

    public function tail(int $lines = 200): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }
        $data = file($this->logFile, FILE_IGNORE_NEW_LINES) ?: [];
        return array_slice($data, -$lines);
    }
}

