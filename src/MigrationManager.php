<?php
namespace VirakCloud\Backup;

class MigrationManager
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Perform a safe search/replace across the DB, handling serialized data.
     */
    public function search_replace(string $from, string $to): void
    {
        global $wpdb;
        $tables = $wpdb->get_col('SHOW TABLES');
        foreach ($tables as $table) {
            $columns = $wpdb->get_results("SHOW COLUMNS FROM `$table`", ARRAY_A);
            $textCols = array_filter($columns, function ($col) {
                return preg_match('/text|char|blob|json/i', $col['Type']);
            });
            foreach ($textCols as $col) {
                $name = $col['Field'];
                $rows = $wpdb->get_results("SELECT `{$name}`, `{$name}` as _orig, `{$name}` as _pk FROM `$table`", ARRAY_A);
                foreach ($rows as $row) {
                    $val = $row[$name];
                    $new = $this->replace_maybe_serialized($val, $from, $to);
                    if ($new !== $val) {
                        $wpdb->update($table, [$name => $new], [$name => $val]);
                    }
                }
            }
        }
        $this->logger->info('migrate_replace_done', ['from' => $from, 'to' => $to]);
    }

    private function replace_maybe_serialized($data, string $from, string $to)
    {
        if (is_serialized($data)) {
            $un = @unserialize((string) $data);
            if ($un !== false || $data === 'b:0;') {
                $replaced = $this->deep_replace($un, $from, $to);
                return serialize($replaced);
            }
        }
        if (is_string($data)) {
            return str_replace($from, $to, $data);
        }
        return $data;
    }

    private function deep_replace($value, string $from, string $to)
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->deep_replace($v, $from, $to);
            }
            return $value;
        }
        if (is_object($value)) {
            foreach ($value as $k => $v) {
                $value->{$k} = $this->deep_replace($v, $from, $to);
            }
            return $value;
        }
        if (is_string($value)) {
            return str_replace($from, $to, $value);
        }
        return $value;
    }
}

