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

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function debug(string $event, array $context = []): void
    {
        $this->write('debug', $event, $context);
    }

    /**
     * Persist simple progress information for UI polling.
     * @param int $percent 0-100
     * @param string $stage
     */
    public function setProgress(int $percent, string $stage): void
    {
        $percent = max(0, min(100, $percent));
        update_option('vcbk_progress', [
            'percent' => $percent,
            'stage' => $stage,
            'ts' => gmdate('c'),
        ], false);
        $this->debug('progress', ['percent' => $percent, 'stage' => $stage]);
    }

    /**
     * @return array{percent:int,stage:string,ts:string}|array{}
     */
    public function getProgress(): array
    {
        $p = get_option('vcbk_progress', []);
        return is_array($p) ? $p : [];
    }

    /**
     * @param array<string, mixed> $context
     */
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

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
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

    /**
     * @return string[]
     */
    public function tail(int $lines = 500): array
    {
        if (!file_exists($this->logFile)) {
            return [];
        }
        $data = file($this->logFile, FILE_IGNORE_NEW_LINES) ?: [];
        return array_slice($data, -$lines);
    }
}
