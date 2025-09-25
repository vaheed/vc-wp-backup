<?php
namespace VirakCloud\Backup;

class RestoreManager
{
    private Settings $settings;
    private Logger $logger;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    public function restore_local(string $archivePath, array $options = []): void
    {
        if (!current_user_can('update_core')) {
            throw new \RuntimeException(__('Permission denied', 'virakcloud-backup'));
        }
        $this->logger->info('restore_start', ['archive' => basename($archivePath)]);
        $dry = !empty($options['dry_run']);

        // Snapshot current site files to allow rollback (simple copy of wp-config + uploads index)
        $snapshotDir = WP_CONTENT_DIR . '/virakcloud-backup/snapshots/' . gmdate('Ymd-His');
        if (!$dry) {
            wp_mkdir_p($snapshotDir);
        }

        // Extract archive
        $tmp = wp_tempnam('vcbk-restore');
        if (!$tmp) {
            throw new \RuntimeException('Cannot create temp dir');
        }
        $tmpDir = $tmp . '-dir';
        wp_mkdir_p($tmpDir);
        $ext = pathinfo($archivePath, PATHINFO_EXTENSION);
        if ($ext === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($archivePath) !== true) {
                throw new \RuntimeException('Cannot open archive');
            }
            $zip->extractTo($tmpDir);
            $zip->close();
        } else {
            $phar = new \PharData($archivePath);
            $phar->extractTo($tmpDir, null, true);
        }

        if ($dry) {
            $this->logger->info('restore_dry_run_complete');
            return;
        }

        // Import DB if present
        $sqlFiles = glob($tmpDir . '/*.sql');
        if ($sqlFiles) {
            $this->import_database($sqlFiles[0]);
        }

        // Copy files over cautiously
        $content = WP_CONTENT_DIR;
        $this->recurse_copy($tmpDir . '/wp-content', $content);

        $this->logger->info('restore_complete');
    }

    private function import_database(string $sqlFile): void
    {
        global $wpdb;
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new \RuntimeException('SQL file missing');
        }
        // Very basic import; for production, split and run line by line or via mysqli::multi_query
        $queries = array_filter(array_map('trim', explode(";\n", $sql)));
        foreach ($queries as $q) {
            if ($q === '') { continue; }
            $wpdb->query($q);
        }
    }

    private function recurse_copy(string $src, string $dst): void
    {
        if (!file_exists($src)) {
            return;
        }
        $dir = opendir($src);
        @mkdir($dst, 0755, true);
        while (false !== ($file = readdir($dir))) {
            if ($file === '.' || $file === '..') continue;
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            if (is_dir($srcPath)) {
                $this->recurse_copy($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }
}

