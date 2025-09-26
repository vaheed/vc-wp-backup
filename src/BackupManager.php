<?php

namespace VirakCloud\Backup;

use Ramsey\Uuid\Uuid;

class BackupManager
{
    private Settings $settings;
    private Logger $logger;

    public function __construct(Settings $settings, Logger $logger)
    {
        $this->settings = $settings;
        $this->logger = $logger;
    }

    /**
     * Run a backup.
     *
     * @param array<string, mixed> $options
     * @return array{key: string, manifest: string, local: string}
     */
    public function run(string $type = 'full', array $options = []): array
    {
        // Make long-running backup safer against timeouts
        @ignore_user_abort(true);
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        if (function_exists('wp_raise_memory_limit')) {
            @wp_raise_memory_limit('admin');
        }
        if (!current_user_can('manage_options') && empty($options['schedule'])) {
            throw new \RuntimeException(__('Permission denied', 'virakcloud-backup'));
        }
        $cfg = $this->settings->get();
        $upload = wp_get_upload_dir();
        $work = trailingslashit($upload['basedir']) . 'virakcloud-backup/work';
        $archives = trailingslashit($upload['basedir']) . 'virakcloud-backup/archives';
        wp_mkdir_p($work);
        wp_mkdir_p($archives);
        // Proactively tidy up previous working artifacts
        $this->cleanupWorkingDirs();

        // Site-scoped directory inside the bucket to avoid collisions across sites
        $keyPrefix = 'backups/' . $this->settings->sitePrefix() . '/';

        $uuid = Uuid::uuid4()->toString();
        $dateStamp = gmdate('Ymd-His');
        $typeSlug = in_array($type, ['full','db','files','incremental'], true) ? $type : 'full';
        $ext = ($cfg['backup']['archive_format'] === 'tar.gz' ? 'tar.gz' : 'zip');
        $archiveName = 'backup-' . $typeSlug . '-' . $dateStamp . '.' . $ext;
        $archivePath = trailingslashit($archives) . $archiveName;

        $this->logger->info('backup_start', ['type' => $type, 'archive' => $archiveName]);
        $this->logger->setProgress(5, __('Queued', 'virakcloud-backup'));

        // Prepare paths
        $paths = $this->resolvePaths($type, $cfg);
        $exclude = $cfg['backup']['exclude'] ?? [];
        // Always exclude our own working directory from backups
        if (!in_array('wp-content/uploads/virakcloud-backup', $exclude, true)) {
            $exclude[] = 'wp-content/uploads/virakcloud-backup';
        }
        $this->logger->debug('paths_resolved', [
            'type' => $type,
            'paths' => $paths,
            'exclude' => $exclude,
        ]);
        $this->logger->setProgress(10, __('Preparing', 'virakcloud-backup'));
        $this->checkControl();

        // DB dump if needed
        $dbDumpPath = null;
        if (in_array($type, ['full', 'db', 'incremental'], true)) {
            $this->logger->debug('db_dump_start');
            $this->logger->setProgress(20, __('Archiving DB', 'virakcloud-backup'));
            $dbDumpPath = $this->dumpDatabase($work);
            if ($dbDumpPath) {
                $paths[] = $dbDumpPath;
            }
            $this->logger->debug('db_dump_complete', ['path' => $dbDumpPath]);
            $this->logger->setProgress(35, __('Archiving Files', 'virakcloud-backup'));
            $this->checkControl();
        }

        // Build archive
        $arch = new ArchiveBuilder($this->logger);
        $this->logger->setProgress(40, __('Archiving Files', 'virakcloud-backup'));
        // Options for ArchiveBuilder (separate from $options passed to this method)
        $archOptions = [];
        if ($type === 'incremental') {
            $last = get_option('vcbk_last_backup');
            $cutoff = is_string($last) ? strtotime($last) : false;
            if ($cutoff !== false) {
                $archOptions['modifiedSince'] = (int) $cutoff;
                $this->logger->debug('incremental_cutoff', ['ts' => $cutoff]);
            }
        }
        $manifest = $arch->build($cfg['backup']['archive_format'], $paths, $archivePath, $exclude, $archOptions);
        $this->logger->setProgress(70, __('Upload Pending', 'virakcloud-backup'));
        $this->checkControl();

        // Generate manifest.json
        $manifestArr = [
            'version' => 1,
            'site' => md5(home_url()),
            'wp_version' => get_bloginfo('version'),
            'db_version' => $GLOBALS['wp_db_version'] ?? null,
            'type' => $type,
            'time' => gmdate('c'),
            'archive' => basename($archivePath),
            'archive_sha256' => $manifest['sha256'],
            'encryption' => $cfg['backup']['encryption'] ?? ['enabled' => false],
        ];
        $manifestJson = wp_json_encode($manifestArr, JSON_PRETTY_PRINT);
        $manifestLocal = $archivePath . '.manifest.json';
        file_put_contents($manifestLocal, $manifestJson);
        $this->logger->debug('manifest_written', ['file' => $manifestLocal]);

        // Upload to S3
        $shouldUpload = !empty($options['schedule']) || !empty($options['upload']);
        $keyArchive = $keyPrefix . $archiveName;
        $keyManifest = $keyPrefix . 'manifest-' . $dateStamp . '.json';
        if ($shouldUpload) {
            $this->logger->debug('s3_upload_init');
            $this->logger->setProgress(80, __('Uploading', 'virakcloud-backup'));
            $this->checkControl();
            $s3 = (new S3ClientFactory($this->settings, $this->logger))->create();
            $bucket = $cfg['s3']['bucket'];
            $uploader = new Uploader($s3, $bucket, $this->logger);
            try {
                $uploader->uploadAuto($keyArchive, $archivePath);
                $s3->putObject([
                    'Bucket' => $bucket,
                    'Key' => $keyManifest,
                    'Body' => $manifestJson,
                    'ContentType' => 'application/json',
                ]);
                $this->logger->info('s3_upload_complete', [
                    'archive' => $keyArchive,
                    'manifest' => $keyManifest,
                ]);
                $this->logger->setProgress(95, __('Verifying', 'virakcloud-backup'));
                // Skip expensive re-hash here; rely on the manifest hash computed earlier
                $ok = isset($manifest['sha256']) && is_string($manifest['sha256']) && $manifest['sha256'] !== '';
                $this->logger->debug('verify', ['ok' => $ok, 'sha256_present' => $ok]);
                $this->logger->setProgress(100, $ok ? __('Complete', 'virakcloud-backup') : __('Finalizing', 'virakcloud-backup'));
                update_option('vcbk_last_s3_upload', current_time('mysql'), false);
                // Keep the freshly created archive locally, prune older ones later
                if (!empty($dbDumpPath) && is_string($dbDumpPath)) {
                    @unlink($dbDumpPath);
                }
                $this->logger->info('local_cleanup', [
                    'work_db_dump_deleted' => !(!empty($dbDumpPath) && file_exists($dbDumpPath)),
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('s3_upload_failed', [
                    'message' => $e->getMessage(),
                ]);
                throw $e;
            }
        } else {
            $this->logger->debug('s3_upload_skipped', ['reason' => 'option']);
            $this->logger->setProgress(95, __('Finalizing', 'virakcloud-backup'));
            // Even without S3 upload, remove temporary DB dump
            if (!empty($dbDumpPath) && is_string($dbDumpPath)) {
                @unlink($dbDumpPath);
            }
        }

        // Retention policies could be applied here (list, prune)
        $this->logger->info('backup_complete', ['archive' => $keyArchive]);
        // Prune local archives to keep disk usage low (keep latest only by default)
        try {
            $deleted = $this->pruneLocal(1);
            if ($deleted > 0) {
                $this->logger->info('local_prune', ['deleted' => $deleted]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('local_prune_failed', ['message' => $e->getMessage()]);
        }
        update_option('vcbk_last_backup', current_time('mysql'));

        return ['key' => $keyArchive, 'manifest' => $keyManifest, 'local' => $archivePath];
    }

    private function cleanupWorkingDirs(): void
    {
        $upload = wp_get_upload_dir();
        $base = trailingslashit($upload['basedir']) . 'virakcloud-backup';
        $paths = [
            $base . '/work',
            $base . '/test',
        ];
        foreach ($paths as $dir) {
            if (is_dir($dir)) {
                $this->rrmdirContents($dir);
            }
        }
        // Purge restore downloads older than 6 hours
        $restore = $base . '/restore';
        if (is_dir($restore)) {
            foreach (glob($restore . '/*') ?: [] as $f) {
                $mt = @filemtime($f);
                if ($mt !== false && $mt < time() - 6 * 3600) {
                    @unlink($f);
                }
            }
        }
    }

    private function rrmdirContents(string $dir): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $p) {
            if ($p->isDir()) {
                @rmdir((string) $p);
            } else {
                @unlink((string) $p);
            }
        }
    }

    private function checkControl(): void
    {
        $ctl = get_option('vcbk_job_control');
        if ($ctl === 'cancel') {
            $this->logger->info('job_cancelled');
            delete_option('vcbk_job_control');
            throw new \RuntimeException(__('Backup cancelled', 'virakcloud-backup'));
        }
        if ($ctl === 'pause') {
            $this->logger->info('job_paused');
            $this->logger->setProgress(50, __('Paused', 'virakcloud-backup'));
            // Leave flag set so UI shows paused; abort gracefully
            throw new \RuntimeException(__('Backup paused', 'virakcloud-backup'));
        }
    }

    /**
     * @param array<string, mixed> $cfg
     * @return string[]
     */
    private function resolvePaths(string $type, array $cfg): array
    {
        $root = ABSPATH;
        $paths = [];
        if ($type === 'db') {
            return $paths; // DB dump handled separately
        }
        if ($type === 'full') {
            // Include the entire WordPress root for a true full-site migration
            $paths[] = rtrim($root, '/');
            // Fallback: some installs keep wp-config.php one level up
            $configAbove = rtrim(dirname($root), '/') . '/wp-config.php';
            if (file_exists($configAbove)) {
                $paths[] = $configAbove;
            }
        } elseif ($type === 'files' || $type === 'incremental') {
            foreach ($cfg['backup']['include'] as $rel) {
                $abs = wp_normalize_path(trailingslashit($root) . ltrim($rel, '/'));
                if (file_exists($abs)) {
                    $paths[] = $abs;
                }
            }
            // Ensure critical config and mu-plugins are captured when not doing a full backup
            $maybe = [ABSPATH . 'wp-config.php', WP_CONTENT_DIR . '/mu-plugins'];
            foreach ($maybe as $p) {
                if (file_exists($p)) {
                    $paths[] = $p;
                }
            }
        }
        return $paths;
    }

    private function dumpDatabase(string $workDir): ?string
    {
        global $wpdb;
        $file = trailingslashit($workDir) . 'db-' . gmdate('Ymd-His') . '.sql';
        $fh = fopen($file, 'wb');
        if (!$fh) {
            return null;
        }
        fwrite($fh, "-- VirakCloud Backup SQL Export\nSET NAMES utf8mb4;\nSET foreign_key_checks = 0;\n");
        $tables = $wpdb->get_col('SHOW TABLES');
        $total = max(1, count($tables));
        $index = 0;
        foreach ($tables as $table) {
            $create = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_A);
            fwrite($fh, "\nDROP TABLE IF EXISTS `$table`;\n" . $create['Create Table'] . ";\n\n");
            $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
            foreach ($rows as $row) {
                $vals = array_map([$this, 'sqlEscape'], array_values($row));
                $columns = array_map(
                    static fn($c): string => '`' . (string) $c . '`',
                    array_keys($row)
                );
                $sql = sprintf(
                    "INSERT INTO `%s` (%s) VALUES (%s);\n",
                    $table,
                    implode(', ', $columns),
                    implode(', ', $vals)
                );
                fwrite($fh, $sql);
            }
            // Update progress within the DB stage (20–35%)
            $index++;
            $pct = (int) floor(20 + (15 * $index / $total));
            $this->logger->setProgress($pct, __('Archiving DB', 'virakcloud-backup'), [
                'table' => (string) $table,
                'tablesProcessed' => $index,
                'tablesTotal' => $total,
            ]);
        }
        fwrite($fh, "SET foreign_key_checks = 1;\n");
        fclose($fh);
        return $file;
    }

    /**
     * @param mixed $value
     */
    private function sqlEscape($value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        $escaped = esc_sql((string) $value);
        if (is_array($escaped)) {
            $escaped = implode(',', array_map('strval', $escaped));
        }
        return "'" . $escaped . "'";
    }

    /**
     * Remove old local archives in uploads/virakcloud-backup/archives, keeping the most recent N.
     * @return int Number of files deleted (archives + manifests)
     */
    public function pruneLocal(int $keep = 1): int
    {
        $keep = max(0, $keep);
        $upload = wp_get_upload_dir();
        $archivesDir = trailingslashit($upload['basedir']) . 'virakcloud-backup/archives';
        if (!is_dir($archivesDir)) {
            return 0;
        }
        // Collect archives (.zip and .tar.gz)
        $files = [];
        foreach (glob($archivesDir . '/backup-*.zip') ?: [] as $f) {
            $files[] = $f;
        }
        foreach (glob($archivesDir . '/backup-*.tar.gz') ?: [] as $f) {
            $files[] = $f;
        }
        if (empty($files)) {
            return 0;
        }
        // Sort by mtime DESC
        usort($files, static function (string $a, string $b): int {
            return filemtime($b) <=> filemtime($a);
        });
        $toDelete = array_slice($files, $keep);
        $deleted = 0;
        foreach ($toDelete as $path) {
            if (is_file($path) && @unlink($path)) {
                $deleted++;
            }
            // Also delete corresponding manifest if present
            $manifest = $path . '.manifest.json';
            if (is_file($manifest) && @unlink($manifest)) {
                $deleted++;
            }
        }
        return $deleted;
    }
}
