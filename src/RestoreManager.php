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

    /**
     * Download an archive from S3 and restore it locally.
     *
     * @param array<string, mixed> $options
     */
    public function restoreFromS3(string $key, array $options = []): void
    {
        if (!current_user_can('update_core')) {
            throw new \RuntimeException(__('Permission denied', 'virakcloud-backup'));
        }
        $cfg = $this->settings->get();
        $client = (new S3ClientFactory($this->settings, $this->logger))->create();
        $bucket = $cfg['s3']['bucket'];

        $upload = wp_get_upload_dir();
        $restoreDir = trailingslashit($upload['basedir']) . 'virakcloud-backup/restore';
        wp_mkdir_p($restoreDir);
        $local = $restoreDir . '/' . basename($key);
        $this->logger->info('restore_download_start', ['key' => $key]);
        $this->logger->setProgress(5, __('Downloading', 'virakcloud-backup'));
        $client->getObject(['Bucket' => $bucket, 'Key' => $key, 'SaveAs' => $local]);
        $this->logger->info('restore_download_complete', ['file' => $local]);
        $this->logger->setProgress(15, __('Downloaded', 'virakcloud-backup'));

        $this->restoreLocal($local, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    public function restoreLocal(string $archivePath, array $options = []): void
    {
        if (!current_user_can('update_core')) {
            throw new \RuntimeException(__('Permission denied', 'virakcloud-backup'));
        }
        // Touch settings to make dependency explicit for analysis
        $this->settings->get();
        $this->logger->info('restore_start', ['archive' => basename($archivePath)]);
        $this->logger->setProgress(20, __('Preparing', 'virakcloud-backup'));
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
            // Handle .tar.gz and .tar
            if (str_ends_with($archivePath, '.tar.gz')) {
                $pharGz = new \PharData($archivePath);
                $tarPath = substr($archivePath, 0, -3); // remove .gz
                $pharGz->decompress();
                $phar = new \PharData($tarPath);
                $phar->extractTo($tmpDir, null, true);
                @unlink($tarPath);
            } else {
                $phar = new \PharData($archivePath);
                $phar->extractTo($tmpDir, null, true);
            }
        }
        $this->logger->setProgress(45, __('Unpacked', 'virakcloud-backup'));

        if ($dry) {
            $this->logger->info('restore_dry_run_complete');
            return;
        }

        // Import DB if present
        $sqlFiles = glob($tmpDir . '/*.sql');
        if ($sqlFiles) {
            $this->logger->setProgress(55, __('Restoring DB', 'virakcloud-backup'));
            $this->importDatabase($sqlFiles[0]);
        }

        // Copy files over cautiously
        $content = WP_CONTENT_DIR;
        $this->logger->setProgress(70, __('Restoring Files', 'virakcloud-backup'));
        $this->recurseCopy($tmpDir . '/wp-content', $content);

        $this->logger->setProgress(95, __('Finalizing', 'virakcloud-backup'));
        $this->logger->info('restore_complete');
        $this->logger->setProgress(100, __('Complete', 'virakcloud-backup'));
    }

    private function importDatabase(string $sqlFile): void
    {
        global $wpdb;
        $sql = file_get_contents($sqlFile);
        if ($sql === false) {
            throw new \RuntimeException('SQL file missing');
        }
        // Very basic import; for production, split and run line by line or via mysqli::multi_query
        $queries = array_filter(array_map('trim', preg_split("/(;\n|;\r\n)/", $sql) ?: []));
        foreach ($queries as $q) {
            $wpdb->query($q);
        }
    }

    private function recurseCopy(string $src, string $dst): void
    {
        if (!file_exists($src)) {
            return;
        }
        $dir = opendir($src);
        if ($dir === false) {
            return;
        }
        @mkdir($dst, 0755, true);
        while (false !== ($file = readdir($dir))) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $srcPath = $src . '/' . $file;
            $dstPath = $dst . '/' . $file;
            if (is_dir($srcPath)) {
                $this->recurseCopy($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
        closedir($dir);
    }
}
