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
        // Avoid timeouts during large downloads
        @ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('wp_raise_memory_limit')) {
            @wp_raise_memory_limit('admin');
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
        $start = 5;
        $end = 18;
        $client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $local,
            '@http' => [
                'progress' => function ($dlTotal, $dlTransferred) use ($start, $end) {
                    if ($dlTotal > 0) {
                        $pct = (int) floor($start + ($end - $start) * ($dlTransferred / max(1, $dlTotal)));
                        $pct = max($start, min($end, $pct));
                        $this->logger->setProgress($pct, __('Downloading', 'virakcloud-backup'), [
                            'bytesDownloaded' => (int) $dlTransferred,
                            'bytesTotal' => (int) $dlTotal,
                        ]);
                    }
                }
            ],
        ]);
        $this->logger->info('restore_download_complete', ['file' => $local]);
        // Verify checksum against manifest when available
        try {
            $this->logger->setProgress(14, __('Verifying download', 'virakcloud-backup'));
            $sha = @hash_file('sha256', $local) ?: '';
            $this->maybeVerifyWithManifest($client, $bucket, $key, $sha);
            $this->logger->debug('restore_download_sha256', ['sha256' => $sha]);
        } catch (\Throwable $e) {
            $this->logger->error('restore_verify_warning', ['message' => $e->getMessage()]);
        }
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
        @ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('wp_raise_memory_limit')) {
            @wp_raise_memory_limit('admin');
        }
        $preservePlugin = array_key_exists('preserve_plugin', $options) ? (bool) $options['preserve_plugin'] : true;
        // Capture current settings so we can optionally preserve/merge creds after DB import
        $preservedSettings = $this->settings->get();
        $dry = !empty($options['dry_run']);

        $this->logger->info('restore_full_start', ['archive' => basename($archivePath)]);
        $this->logger->setProgress(10, __('Preparing', 'virakcloud-backup'));

        // Extract with robust error handling
        try {
            $tmp = wp_tempnam('vcbk-restore-full');
            if (!$tmp) {
                throw new \RuntimeException('Cannot create temp file');
            }
            $tmpDir = $tmp . '-dir';
            wp_mkdir_p($tmpDir);
            $type = $this->detectArchiveType($archivePath);
            if ($type === 'zip') {
                // Prefer WordPress core unzip implementation for better compatibility
                if (!function_exists('unzip_file')) {
                    @require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                if (function_exists('WP_Filesystem')) {
                    // Initialize filesystem to prefer 'direct' method
                    @WP_Filesystem();
                }
                $unzipped = false;
                if (function_exists('unzip_file')) {
                    $res = unzip_file($archivePath, $tmpDir);
                    if ($res === true) {
                        $unzipped = true;
                    } elseif (is_wp_error($res)) {
                        // Fall back to ZipArchive below with detailed status
                        $this->logger->debug('wp_unzip_failed', ['message' => $res->get_error_message()]);
                    }
                }
                if (!$unzipped) {
                    if (!class_exists('ZipArchive')) {
                        throw new \RuntimeException('PHP Zip extension is not installed. Please install php-zip.');
                    }
                    $zip = new \ZipArchive();
                    $code = $zip->open($archivePath);
                    if ($code !== true) {
                        $msg = method_exists($zip, 'getStatusString') ? (string) $zip->getStatusString() : '';
                        $this->logger->error('zip_open_failed', [
                            'code' => $code,
                            'status' => $msg,
                            'size' => @filesize($archivePath),
                        ]);
                        throw new \RuntimeException('Cannot open ZIP archive (code ' . (string) $code . ') ' . $msg);
                    }
                    $zip->extractTo($tmpDir);
                    $zip->close();
                }
            } else {
                if ($type === 'tar.gz') {
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
        } catch (\Throwable $e) {
            $this->logger->error('restore_full_extract_failed', ['message' => $e->getMessage()]);
            $this->logger->setProgress(12, __('Extraction failed', 'virakcloud-backup'));
            throw $e;
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
        $sqlPaths = array_merge(
            glob($tmpDir . '/*.sql') ?: [],
            glob($srcRoot . '/*.sql') ?: []
        );
        if (!empty($sqlPaths)) {
            $this->logger->setProgress(45, __('Restoring DB', 'virakcloud-backup'));
            $this->importDatabase($sqlPaths[0]);
            // Optionally preserve plugin settings from the current site (e.g., S3 keys)
            if ($preservePlugin) {
                update_option('vcbk_settings', $preservedSettings, false);
                $this->logger->info('restore_settings_preserved');
            } else {
                // Fallback: if imported settings are missing critical S3 fields, merge from preserved
                $imported = get_option('vcbk_settings', []);
                if (!is_array($imported)) {
                    $imported = [];
                }
                $s3 = $imported['s3'] ?? [];
                $needMerge = empty($s3['bucket']) || empty($s3['access_key']) || empty($s3['secret_key']);
                if ($needMerge) {
                    $imported['s3'] = array_merge($preservedSettings['s3'] ?? [], $s3);
                    update_option('vcbk_settings', $imported, false);
                    $this->logger->info('restore_settings_merged');
                }
            }
            $this->logger->info('restore_db_import_complete');
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
        if (isset($options['migrate'])) {
            $migrate = $options['migrate'];
            $from = (string) $migrate['from'];
            $to = (string) $migrate['to'];
            if ($from !== '' && $to !== '' && $from !== $to) {
                $this->logger->setProgress(85, __('Rewriting URLs', 'virakcloud-backup'));
                (new MigrationManager($this->logger))->searchReplace($from, $to);
                update_option('home', $to);
                update_option('siteurl', $to);
                $this->logger->info('migrate_post_restore', ['from' => $from, 'to' => $to]);
            }
        }

        $this->logger->setProgress(95, __('Finalizing', 'virakcloud-backup'));
        $this->cleanupRestoreArtifacts($archivePath, $tmpDir);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
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
        $this->logger->setProgress(5, __('Downloading', 'virakcloud-backup'));
        $start = 5;
        $end = 18;
        $client->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
            'SaveAs' => $local,
            '@http' => [
                'progress' => function ($dlTotal, $dlTransferred) use ($start, $end) {
                    if ($dlTotal > 0) {
                        $pct = (int) floor($start + ($end - $start) * ($dlTransferred / max(1, $dlTotal)));
                        $pct = max($start, min($end, $pct));
                        $this->logger->setProgress($pct, __('Downloading', 'virakcloud-backup'), [
                            'bytesDownloaded' => (int) $dlTransferred,
                            'bytesTotal' => (int) $dlTotal,
                        ]);
                    }
                }
            ],
        ]);
        // Verify checksum
        try {
            $this->logger->setProgress(14, __('Verifying download', 'virakcloud-backup'));
            $sha = @hash_file('sha256', $local) ?: '';
            $this->maybeVerifyWithManifest($client, $bucket, $key, $sha);
            $this->logger->debug('restore_download_sha256', ['sha256' => $sha]);
        } catch (\Throwable $e) {
            $this->logger->error('restore_verify_warning', ['message' => $e->getMessage()]);
        }
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
        @ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('wp_raise_memory_limit')) {
            @wp_raise_memory_limit('admin');
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

        // Extract archive (non-full mode) with error handling
        try {
            $tmp = wp_tempnam('vcbk-restore');
            if (!$tmp) {
                throw new \RuntimeException('Cannot create temp dir');
            }
            $tmpDir = $tmp . '-dir';
            wp_mkdir_p($tmpDir);
            $type = $this->detectArchiveType($archivePath);
            if ($type === 'zip') {
                if (!function_exists('unzip_file')) {
                    @require_once ABSPATH . 'wp-admin/includes/file.php';
                }
                if (function_exists('WP_Filesystem')) {
                    @WP_Filesystem();
                }
                $unzipped = false;
                if (function_exists('unzip_file')) {
                    $res = unzip_file($archivePath, $tmpDir);
                    if ($res === true) {
                        $unzipped = true;
                    } elseif (is_wp_error($res)) {
                        $this->logger->debug('wp_unzip_failed', ['message' => $res->get_error_message()]);
                    }
                }
                if (!$unzipped) {
                    if (!class_exists('ZipArchive')) {
                        throw new \RuntimeException('PHP Zip extension is not installed. Please install php-zip.');
                    }
                    $zip = new \ZipArchive();
                    $code = $zip->open($archivePath);
                    if ($code !== true) {
                        $msg = method_exists($zip, 'getStatusString') ? (string) $zip->getStatusString() : '';
                        $this->logger->error('zip_open_failed', [
                            'code' => $code,
                            'status' => $msg,
                            'size' => @filesize($archivePath),
                        ]);
                        throw new \RuntimeException('Cannot open ZIP archive (code ' . (string) $code . ') ' . $msg);
                    }
                    $zip->extractTo($tmpDir);
                    $zip->close();
                }
            } else {
                // Handle .tar.gz and .tar
                if ($type === 'tar.gz') {
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
        } catch (\Throwable $e) {
            $this->logger->error('restore_extract_failed', ['message' => $e->getMessage()]);
            $this->logger->setProgress(22, __('Extraction failed', 'virakcloud-backup'));
            throw $e;
        }

        // Log detected archive type for troubleshooting
        $this->logger->debug('restore_detected_archive', ['type' => $this->detectArchiveType($archivePath), 'file' => basename($archivePath)]);
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
        $this->copyDirWithProgress($srcRoot . '/wp-content', $content, 70, 90);

        // Optional post-restore URL rewrite (migration)
        if (isset($options['migrate'])) {
            $migrate = $options['migrate'];
            $from = (string) $migrate['from'];
            $to = (string) $migrate['to'];
            if ($from !== '' && $to !== '' && $from !== $to) {
                $this->logger->setProgress(85, __('Rewriting URLs', 'virakcloud-backup'));
                (new MigrationManager($this->logger))->searchReplace($from, $to);
                update_option('home', $to);
                update_option('siteurl', $to);
                $this->logger->info('migrate_post_restore', ['from' => $from, 'to' => $to]);
            }
        }

        $this->logger->setProgress(95, __('Finalizing', 'virakcloud-backup'));
        // Cleanup temporary extraction and downloaded archive
        $this->cleanupRestoreArtifacts($archivePath, $tmpDir);
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
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

    // Legacy simple copy kept in history; use copyDirWithProgress instead.

    private function cleanupRestoreArtifacts(string $archivePath, ?string $tmpDir): void
    {
        // Remove extracted temp directory
        if ($tmpDir && is_dir($tmpDir)) {
            $this->rrmdir($tmpDir);
        }
        // Remove downloaded archive if it is under uploads/virakcloud-backup/restore
        $uploads = wp_get_upload_dir();
        $restoreBase = trailingslashit($uploads['basedir']) . 'virakcloud-backup/restore/';
        $normArchive = wp_normalize_path($archivePath);
        if (str_starts_with($normArchive, wp_normalize_path($restoreBase)) && is_file($archivePath)) {
            @unlink($archivePath);
            $this->logger->info('restore_cleanup_archive_deleted', ['file' => basename($archivePath)]);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            if ($path->isDir()) {
                @rmdir((string) $path);
            } else {
                @unlink((string) $path);
            }
        }
        @rmdir($dir);
    }

    /**
     * Best-effort archive type detection by magic bytes.
     * Returns 'zip', 'tar.gz' or 'tar'.
     */
    private function detectArchiveType(string $file): string
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return pathinfo($file, PATHINFO_EXTENSION) === 'zip' ? 'zip' : 'tar.gz';
        }
        $head = @fread($fh, 8) ?: '';
        @fclose($fh);
        $bytes = bin2hex((string) $head);
        // Zip: 50 4b 03 04 or 50 4b 05 06 (empty) or 50 4b 07 08
        if (str_starts_with($bytes, '504b03') || str_starts_with($bytes, '504b05') || str_starts_with($bytes, '504b07')) {
            return 'zip';
        }
        // Gzip: 1f 8b
        if (str_starts_with($bytes, '1f8b')) {
            return 'tar.gz';
        }
        // Fallback: trust extension
        $ext = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'zip') {
            return 'zip';
        }
        if ($ext === 'gz') {
            return 'tar.gz';
        }
        return 'tar';
    }

    private function copyDirWithProgress(string $src, string $dst, int $startPercent = 70, int $endPercent = 90): void
    {
        if (!is_dir($src)) {
            return;
        }
        // Scan for total size
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)
        );
        $total = 0;
        foreach ($it as $p => $info) {
            if ($info->isFile()) {
                $total += @filesize((string) $p) ?: 0;
            }
        }
        $copied = 0;
        $it2 = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($it2 as $path => $info) {
            $rel = ltrim(substr((string) $path, strlen($src)), '/');
            $target = rtrim($dst, '/') . '/' . $rel;
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
                $copied += @filesize((string) $path) ?: 0;
                if ($total > 0) {
                    $pct = (int) floor($startPercent + ($endPercent - $startPercent) * ($copied / max(1, $total)));
                    $pct = max($startPercent, min($endPercent, $pct));
                    $this->logger->setProgress($pct, __('Restoring Files', 'virakcloud-backup'), [
                        'bytesCopied' => $copied,
                        'bytesTotal' => $total,
                    ]);
                }
            }
        }
    }

    /**
     * Attempt to fetch the manifest for a given backup key and compare sha256.
     * Throws RuntimeException if mismatch is detected.
     */
    private function maybeVerifyWithManifest(\Aws\S3\S3Client $client, string $bucket, string $archiveKey, string $shaLocal): void
    {
        // Expect keys like backups/<site-prefix>/backup-<type>-YYYYmmdd-His.ext
        $base = basename($archiveKey);
        if (!preg_match('/backup-[^-]+-(\d{8}-\d{6})\./', $base, $m)) {
            return; // unknown pattern
        }
        $ts = $m[1];
        $prefix = dirname($archiveKey);
        $manifestKey = rtrim($prefix, '/') . '/manifest-' . $ts . '.json';
        try {
            $res = $client->getObject(['Bucket' => $bucket, 'Key' => $manifestKey]);
            $json = (string) $res['Body'];
            $data = json_decode($json, true);
            if (is_array($data) && !empty($data['archive_sha256']) && is_string($data['archive_sha256'])) {
                $shaManifest = strtolower($data['archive_sha256']);
                if ($shaLocal !== '' && strtolower($shaLocal) !== $shaManifest) {
                    throw new \RuntimeException('Checksum mismatch with manifest ' . $manifestKey);
                }
            }
        } catch (\Aws\S3\Exception\S3Exception $e) {
            // Manifest missing is not fatal; just skip
        }
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
