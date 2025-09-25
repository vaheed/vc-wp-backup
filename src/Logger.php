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
        $this->logFile = $this->currentLogFile();
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
    /**
     * @param array<string, mixed> $extra
     */
    public function setProgress(int $percent, string $stage, array $extra = []): void
    {
        $percent = max(0, min(100, $percent));
        $payload = array_merge([
            'percent' => $percent,
            'stage' => $stage,
            'ts' => gmdate('c'),
        ], $extra);
        update_option('vcbk_progress', $payload, false);
        $this->debug('progress', $payload);
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
        $file = $this->currentLogFile();
        $this->logFile = $file;
        file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
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
        $file = $this->currentLogFile();
        if (!file_exists($file)) {
            $candidates = glob($this->logDir . '/vcbk-*.log');
            if (!$candidates) {
                return [];
            }
            rsort($candidates);
            $file = $candidates[0];
        }
        $data = file($file, FILE_IGNORE_NEW_LINES) ?: [];
        return array_slice($data, -$lines);
    }

    /**
     * @param int $lines
     * @param string|null $level One of info|debug|error
     * @return string[]
     */
    public function tailFiltered(int $lines = 500, ?string $level = null): array
    {
        $rows = $this->tail(max($lines, 1));
        if ($level === null || $level === '') {
            return $rows;
        }
        $level = (string) $level;
        $out = [];
        foreach ($rows as $r) {
            $j = json_decode($r, true);
            if (is_array($j) && isset($j['level']) && $j['level'] === $level) {
                $out[] = $r;
            }
        }
        return $out;
    }

    private function currentLogFile(): string
    {
        return $this->logDir . '/vcbk-' . gmdate('Y-m-d') . '.log';
    }

    public function latestLogFile(): ?string
    {
        $file = $this->currentLogFile();
        if (file_exists($file)) {
            return $file;
        }
        $candidates = glob($this->logDir . '/vcbk-*.log');
        if (!$candidates) {
            return null;
        }
        rsort($candidates);
        return $candidates[0];
    }
}
