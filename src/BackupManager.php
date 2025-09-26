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
        if (!current_user_can('manage_options') && empty($options['schedule'])) {
            throw new \RuntimeException(__('Permission denied', 'virakcloud-backup'));
        }
        $cfg = $this->settings->get();
        $upload = wp_get_upload_dir();
        $work = trailingslashit($upload['basedir']) . 'virakcloud-backup/work';
        $archives = trailingslashit($upload['basedir']) . 'virakcloud-backup/archives';
        wp_mkdir_p($work);
        wp_mkdir_p($archives);

        // Single S3 directory for all backups with datetime-based filenames
        $keyPrefix = 'backups/';

        $uuid = Uuid::uuid4()->toString();
        $dateStamp = gmdate('Ymd-His');
        $archiveName = 'backup-' . $dateStamp . '.' . ($cfg['backup']['archive_format'] === 'tar.gz' ? 'tar.gz' : 'zip');
        $archivePath = trailingslashit($archives) . $archiveName;

        $this->logger->info('backup_start', ['type' => $type, 'archive' => $archiveName]);
        $this->logger->setProgress(5, __('Queued', 'virakcloud-backup'));

        // Prepare paths
        $paths = $this->resolvePaths($type, $cfg);
        $exclude = $cfg['backup']['exclude'] ?? [];
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
        $manifest = $arch->build($cfg['backup']['archive_format'], $paths, $archivePath, $exclude);
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
                ]);
                $this->logger->info('s3_upload_complete', [
                    'archive' => $keyArchive,
                    'manifest' => $keyManifest,
                ]);
                $this->logger->setProgress(95, __('Verifying', 'virakcloud-backup'));
                // Very light verify: re-hash local archive matches manifest
                $ok = hash_file('sha256', $archivePath) === ($manifest['sha256'] ?? '');
                $this->logger->debug('verify', ['ok' => $ok]);
                $this->logger->setProgress(100, $ok ? __('Complete', 'virakcloud-backup') : __('Finalizing', 'virakcloud-backup'));
                update_option('vcbk_last_s3_upload', current_time('mysql'), false);
                // Cleanup local artifacts after successful upload
                @unlink($archivePath);
                @unlink($manifestLocal);
                if (!empty($dbDumpPath) && is_string($dbDumpPath)) {
                    @unlink($dbDumpPath);
                }
                $this->logger->info('local_cleanup', [
                    'archive_deleted' => !file_exists($archivePath),
                    'manifest_deleted' => !file_exists($manifestLocal),
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
        }

        // Retention policies could be applied here (list, prune)
        $this->logger->info('backup_complete', ['archive' => $keyArchive]);
        update_option('vcbk_last_backup', current_time('mysql'));

        return ['key' => $keyArchive, 'manifest' => $keyManifest, 'local' => $archivePath];
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
}
