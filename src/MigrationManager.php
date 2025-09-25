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
    public function searchReplace(string $from, string $to): void
    {
        global $wpdb;
        $tables = $wpdb->get_col('SHOW TABLES');
        foreach ($tables as $table) {
            $columns = $wpdb->get_results("SHOW COLUMNS FROM `$table`", ARRAY_A);
            $textCols = array_filter($columns, function ($col): bool {
                return (bool) preg_match('/text|char|blob|json/i', $col['Type']);
            });
            foreach ($textCols as $col) {
                $name = $col['Field'];
                $sql = sprintf(
                    'SELECT `%1$s`, `%1$s` as _orig, `%1$s` as _pk FROM `%2$s`',
                    $name,
                    $table
                );
                $rows = $wpdb->get_results($sql, ARRAY_A);
                foreach ($rows as $row) {
                    $val = $row[$name];
                    $new = $this->replaceMaybeSerialized($val, $from, $to);
                    if ($new !== $val) {
                        $wpdb->update($table, [$name => $new], [$name => $val]);
                    }
                }
            }
        }
        $this->logger->info('migrate_replace_done', ['from' => $from, 'to' => $to]);
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private function replaceMaybeSerialized(mixed $data, string $from, string $to): mixed
    {
        if (is_serialized($data)) {
            $un = @unserialize((string) $data);
            if ($un !== false || $data === 'b:0;') {
                $replaced = $this->deepReplace($un, $from, $to);
                return serialize($replaced);
            }
        }
        if (is_string($data)) {
            return str_replace($from, $to, $data);
        }
        return $data;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function deepReplace(mixed $value, string $from, string $to): mixed
    {
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->deepReplace($v, $from, $to);
            }
            return $value;
        }
        if (is_object($value)) {
            foreach (get_object_vars($value) as $k => $v) {
                $value->{$k} = $this->deepReplace($v, $from, $to);
            }
            return $value;
        }
        if (is_string($value)) {
            return str_replace($from, $to, $value);
        }
        return $value;
    }
}
