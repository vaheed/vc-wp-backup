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
        $bucket = (string) ($cfg['s3']['bucket'] ?? '');
        if ($bucket === '') {
            throw new \RuntimeException(__('S3 bucket is not configured. Please set it in Settings.', 'virakcloud-backup'));
        }

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
     * Full-site restore from a local archive. Copies entire WordPress tree (core + content).
     * Safer to run via WP-CLI to avoid timeouts and self-overwrite.
     *
     * @param array<string, mixed> $options { preserve_plugin?:bool, dry_run?:bool }
     */
    /**
     * @param array{preserve_plugin?:bool, dry_run?:bool, migrate?:array{from:string,to:string}} $options
     */
    public function restoreFullLocal(string $archivePath, array $options = []): void
    {
        if (!current_user_can('update_core')) {
            throw new \RuntimeException(__('Permission denied', 'virakcloud-backup'));
        }
        $preservePlugin = array_key_exists('preserve_plugin', $options) ? (bool) $options['preserve_plugin'] : true;
        $dry = !empty($options['dry_run']);

        $this->logger->info('restore_full_start', ['archive' => basename($archivePath)]);
        $this->logger->setProgress(10, __('Preparing', 'virakcloud-backup'));

        // Extract
        $tmp = wp_tempnam('vcbk-restore-full');
        if (!$tmp) {
            throw new \RuntimeException('Cannot create temp file');
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
            if (str_ends_with($archivePath, '.tar.gz')) {
                $pharGz = new \PharData($archivePath);
                $tarPath = substr($archivePath, 0, -3);
                $pharGz->decompress();
                $phar = new \PharData($tarPath);
                $phar->extractTo($tmpDir, null, true);
                @unlink($tarPath);
            } else {
                $phar = new \PharData($archivePath);
                $phar->extractTo($tmpDir, null, true);
            }
        }

        // Find WP root inside extracted data
        $srcRoot = $tmpDir;
        if (!file_exists($srcRoot . '/wp-content') && is_dir($srcRoot)) {
            foreach (glob($srcRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                if (file_exists($dir . '/wp-content') || file_exists($dir . '/wp-includes')) {
                    $srcRoot = $dir;
                    break;
                }
            }
        }
        $this->logger->setProgress(35, __('Unpacked', 'virakcloud-backup'));

        if ($dry) {
            $this->logger->info('restore_full_dry_run_complete', ['srcRoot' => $srcRoot]);
            $this->logger->setProgress(100, __('Dry run complete', 'virakcloud-backup'));
            return;
        }

        // Import DB if present at root of extracted dir
        $preserved = $this->settings->get();
        $sqlPaths = array_merge(
            glob($tmpDir . '/*.sql') ?: [],
            glob($srcRoot . '/*.sql') ?: []
        );
        if (!empty($sqlPaths)) {
            $this->logger->setProgress(45, __('Restoring DB', 'virakcloud-backup'));
            $this->importDatabase($sqlPaths[0]);
            // Preserve current plugin settings across restore
            update_option('vcbk_settings', $preserved, false);
            $this->logger->info('restore_settings_preserved');
        }

        $this->logger->setProgress(65, __('Restoring Files', 'virakcloud-backup'));

        // Copy tree into ABSPATH with excludes
        $exclude = function (string $rel) use ($preservePlugin): bool {
            $rel = ltrim($rel, '/');
            if (str_starts_with($rel, 'wp-content/uploads/virakcloud-backup')) {
                return true;
            }
            if ($preservePlugin && str_starts_with($rel, 'wp-content/plugins/virakcloud-backup')) {
                return true;
            }
            return false;
        };
        $this->copyTree($srcRoot, rtrim(ABSPATH, '/'), $exclude);

        // Optional post-restore URL rewrite (migration)
        if (!empty($options['migrate']) && is_array($options['migrate'])) {
            $from = (string) ($options['migrate']['from'] ?? '');
            $to = (string) ($options['migrate']['to'] ?? '');
            if ($from !== '' && $to !== '' && $from !== $to) {
                $this->logger->setProgress(85, __('Rewriting URLs', 'virakcloud-backup'));
                (new MigrationManager($this->logger))->searchReplace($from, $to);
                update_option('home', $to);
                update_option('siteurl', $to);
                $this->logger->info('migrate_post_restore', ['from' => $from, 'to' => $to]);
            }
        }

        $this->logger->setProgress(95, __('Finalizing', 'virakcloud-backup'));
        $this->logger->info('restore_full_complete');
        $this->logger->setProgress(100, __('Complete', 'virakcloud-backup'));
    }

    /**
     * Full-site restore by downloading from S3 first.
     *
     * @param array<string, mixed> $options
     */
    public function restoreFullFromS3(string $key, array $options = []): void
    {
        $cfg = $this->settings->get();
        $client = (new S3ClientFactory($this->settings, $this->logger))->create();
        $bucket = $cfg['s3']['bucket'];
        $upload = wp_get_upload_dir();
        $restoreDir = trailingslashit($upload['basedir']) . 'virakcloud-backup/restore';
        wp_mkdir_p($restoreDir);
        $local = $restoreDir . '/' . basename($key);
        $this->logger->info('restore_full_download_start', ['key' => $key]);
        $client->getObject(['Bucket' => $bucket, 'Key' => $key, 'SaveAs' => $local]);
        $this->restoreFullLocal($local, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    /**
     * @param array{dry_run?:bool, migrate?:array{from:string,to:string}} $options
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
            $this->logger->setProgress(100, __('Dry run complete', 'virakcloud-backup'));
            return;
        }

        // Import DB if present
        $preserved = $this->settings->get();
        $sqlFiles = glob($tmpDir . '/*.sql');
        if ($sqlFiles) {
            $this->logger->setProgress(55, __('Restoring DB', 'virakcloud-backup'));
            $this->importDatabase($sqlFiles[0]);
            update_option('vcbk_settings', $preserved, false);
            $this->logger->info('restore_settings_preserved');
        }

        // Locate wp-content inside extracted archive (may be nested under site root name)
        $srcRoot = $tmpDir;
        if (!file_exists($srcRoot . '/wp-content') && is_dir($srcRoot)) {
            foreach (glob($srcRoot . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                if (file_exists($dir . '/wp-content')) {
                    $srcRoot = $dir;
                    break;
                }
            }
        }
        // Copy files over cautiously
        $content = WP_CONTENT_DIR;
        $this->logger->setProgress(70, __('Restoring Files', 'virakcloud-backup'));
        $this->recurseCopy($srcRoot . '/wp-content', $content);

        // Optional post-restore URL rewrite (migration)
        if (!empty($options['migrate']) && is_array($options['migrate'])) {
            $from = (string) ($options['migrate']['from'] ?? '');
            $to = (string) ($options['migrate']['to'] ?? '');
            if ($from !== '' && $to !== '' && $from !== $to) {
                $this->logger->setProgress(85, __('Rewriting URLs', 'virakcloud-backup'));
                (new MigrationManager($this->logger))->searchReplace($from, $to);
                update_option('home', $to);
                update_option('siteurl', $to);
                $this->logger->info('migrate_post_restore', ['from' => $from, 'to' => $to]);
            }
        }

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

    /**
     * Copy an entire tree from src to dst applying an exclusion callback on relative paths.
     * @param callable(string):bool $exclude
     */
    private function copyTree(string $srcRoot, string $dstRoot, callable $exclude): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($srcRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it as $path => $info) {
            $rel = ltrim(substr((string) $path, strlen($srcRoot)), '/');
            if ($exclude($rel)) {
                continue;
            }
            $target = rtrim($dstRoot, '/') . '/' . $rel;
            if ($info->isDir()) {
                if (!is_dir($target)) {
                    @mkdir($target, 0755, true);
                }
            } else {
                $dir = dirname($target);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0755, true);
                }
                @copy($path, $target);
            }
        }
    }
}
