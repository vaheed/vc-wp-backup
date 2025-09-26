<?php

namespace VirakCloud\Backup\Admin;

use VirakCloud\Backup\Logger;
use VirakCloud\Backup\Scheduler;
use VirakCloud\Backup\Settings;
use VirakCloud\Backup\BackupManager;
use VirakCloud\Backup\RestoreManager;
use VirakCloud\Backup\S3ClientFactory;

class Admin
{
    private Settings $settings;
    private Scheduler $scheduler;
    private Logger $logger;

    public function __construct(Settings $settings, Scheduler $scheduler, Logger $logger)
    {
        $this->settings = $settings;
        $this->scheduler = $scheduler;
        $this->logger = $logger;
    }

    public function hooks(): void
    {
        // Hint for static analysis: read the property so it's not considered "write-only".
        $_ = $this->scheduler;
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_vcbk_save_settings', [$this, 'handleSaveSettings']);
        add_action('admin_post_vcbk_run_backup', [$this, 'handleRunBackup']);
        add_action('admin_post_vcbk_test_s3', [$this, 'handleTestS3']);
        add_action('admin_post_vcbk_run_test', [$this, 'handleRunTest']);
        add_action('admin_post_vcbk_download_log', [$this, 'handleDownloadLog']);
        add_action('admin_post_vcbk_download_backup', [$this, 'handleDownloadBackup']);
        add_action('admin_post_vcbk_upload_backup', [$this, 'handleUploadBackup']);
        add_action('admin_post_vcbk_run_restore', [$this, 'handleRunRestore']);
        add_action('admin_post_vcbk_upload_restore', [$this, 'handleUploadRestore']);
        add_action('wp_ajax_vcbk_tail_logs', [$this, 'ajaxTailLogs']);
        add_action('wp_ajax_vcbk_progress', [$this, 'ajaxProgress']);
        add_action('wp_ajax_vcbk_job_control', [$this, 'ajaxJobControl']);
    }

    public function menu(): void
    {
        add_menu_page(
            __('VirakCloud Backup', 'virakcloud-backup'),
            __('VirakCloud Backup', 'virakcloud-backup'),
            'manage_options',
            'vcbk',
            [$this, 'renderDashboard'],
            'dashicons-cloud'
        );
        // Setup Wizard removed
        add_submenu_page(
            'vcbk',
            __('Settings', 'virakcloud-backup'),
            __('Settings', 'virakcloud-backup'),
            'manage_options',
            'vcbk-settings',
            [$this, 'renderSettings']
        );
        add_submenu_page(
            'vcbk',
            __('Schedules', 'virakcloud-backup'),
            __('Schedules', 'virakcloud-backup'),
            'manage_options',
            'vcbk-schedules',
            [$this, 'renderSchedules']
        );
        add_submenu_page(
            'vcbk',
            __('Backups', 'virakcloud-backup'),
            __('Backups', 'virakcloud-backup'),
            'manage_options',
            'vcbk-backups',
            [$this, 'renderBackups']
        );
        add_submenu_page(
            'vcbk',
            __('Restore', 'virakcloud-backup'),
            __('Restore', 'virakcloud-backup'),
            'update_core',
            'vcbk-restore',
            [$this, 'renderRestore']
        );
        add_submenu_page(
            'vcbk',
            __('Logs', 'virakcloud-backup'),
            __('Logs', 'virakcloud-backup'),
            'manage_options',
            'vcbk-logs',
            [$this, 'renderLogs']
        );
    }

    public function registerSettings(): void
    {
        register_setting('vcbk', 'vcbk_settings');
    }

    public function handleSaveSettings(): void
    {
        check_admin_referer('vcbk_save_settings');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        $data = wp_unslash($_POST);
        $config = $this->settings->sanitizeFromPost($data);
        $this->settings->set($config);
        wp_safe_redirect(add_query_arg('updated', '1', wp_get_referer() ?: admin_url('admin.php?page=vcbk-settings')));
        exit;
    }

    public function handleRunBackup(): void
    {
        check_admin_referer('vcbk_run_backup');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        $type = isset($_POST['type']) ? sanitize_text_field((string) $_POST['type']) : 'full';
        $doUpload = !empty($_POST['upload_to_s3']);
        $bm = new BackupManager($this->settings, $this->logger);
        try {
            $bm->run($type, ['schedule' => false, 'upload' => $doUpload]);
            $msg = $doUpload
                ? __('Backup running and uploading to S3. Check logs for progress.', 'virakcloud-backup')
                : __('Backup running (no S3 upload). Check logs for progress.', 'virakcloud-backup');
            wp_safe_redirect(add_query_arg('message', rawurlencode($msg), admin_url('admin.php?page=vcbk-backups')));
        } catch (\Throwable $e) {
            $msg = __('Backup failed: ', 'virakcloud-backup') . $e->getMessage();
            wp_safe_redirect(add_query_arg('error', rawurlencode($msg), admin_url('admin.php?page=vcbk-backups')));
        }
        exit;
    }

    public function handleTestS3(): void
    {
        check_admin_referer('vcbk_test_s3');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        $client = (new S3ClientFactory($this->settings, $this->logger))->create();
        try {
            $client->headBucket(['Bucket' => $this->settings->get()['s3']['bucket']]);
            $this->logger->info('s3_head_bucket_ok');
            $msg = __('S3 connection success!', 'virakcloud-backup');
            wp_safe_redirect(add_query_arg('message', rawurlencode($msg), admin_url('admin.php?page=vcbk-settings')));
        } catch (\Throwable $e) {
            $this->logger->error('s3_head_bucket_failed', ['message' => $e->getMessage()]);
            $msg = __('S3 connection failed: ', 'virakcloud-backup') . $e->getMessage();
            wp_safe_redirect(add_query_arg('error', rawurlencode($msg), admin_url('admin.php?page=vcbk-settings')));
        }
        exit;
    }

    public function handleRunTest(): void
    {
        check_admin_referer('vcbk_run_test');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        try {
            $upload = wp_get_upload_dir();
            $work = trailingslashit($upload['basedir']) . 'virakcloud-backup/test';
            wp_mkdir_p($work);
            $start = microtime(true);
            // Create small fixture
            $dummy = $work . '/dummy.txt';
            file_put_contents($dummy, str_repeat('x', 2048));
            $export = $work . '/settings.json';
            file_put_contents($export, wp_json_encode($this->settings->get()));
            $archPath = $work . '/test-' . gmdate('Ymd-His') . '.zip';
            $arch = new \ZipArchive();
            $arch->open($archPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
            $arch->addFile($dummy, 'dummy.txt');
            $arch->addFile($export, 'settings.json');
            $arch->close();
            $checksum = hash_file('sha256', $archPath);
            $elapsed = microtime(true) - $start;
            $size = filesize($archPath) ?: 1;
            $throughput = $size / max(0.001, $elapsed);

            // Upload to S3
            $s3 = (new S3ClientFactory($this->settings, $this->logger))->create();
            $bucket = $this->settings->get()['s3']['bucket'];
            $sitePrefix = (new \VirakCloud\Backup\Settings())->sitePrefix();
            $key = 'vcbk-test/' . $sitePrefix . '/' . basename($archPath);
            $s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => fopen($archPath, 'rb')]);
            $this->logger->info('test_backup_complete', [
                'ms' => (int) round($elapsed * 1000),
                'size' => $size,
                'sha256' => $checksum,
            ]);
            update_option('vcbk_last_test', [
                'latency_ms' => (int) round($elapsed * 1000),
                'size' => $size,
                'sha256' => $checksum,
                'key' => $key,
            ], false);
            $msg = sprintf(
                /* translators: 1: milliseconds 2: kib/s */
                __('Test backup uploaded in %1$d ms (%.1f KiB/s).', 'virakcloud-backup'),
                (int) round($elapsed * 1000),
                $throughput / 1024
            );
            wp_safe_redirect(add_query_arg('message', rawurlencode($msg), admin_url('admin.php?page=vcbk-backups')));
        } catch (\Throwable $e) {
            $msg = __('Test backup failed: ', 'virakcloud-backup') . $e->getMessage();
            wp_safe_redirect(add_query_arg('error', rawurlencode($msg), admin_url('admin.php?page=vcbk-backups')));
        }
        exit;
    }

    public function renderDashboard(): void
    {
        echo '<div class="wrap vcbk-wrap">';
        echo '<h1>' . esc_html__('VirakCloud Backup', 'virakcloud-backup') . '</h1>';
        // Summary cards
        $last = get_option('vcbk_last_backup', '—');
        $nextTs = wp_next_scheduled('vcbk_cron_run');
        $tz = wp_timezone();
        $nextText = $nextTs ? wp_date('Y-m-d H:i', (int) $nextTs, $tz) : __('not scheduled', 'virakcloud-backup');
        echo '<div class="vcbk-grid">';
        // Last backup card
        echo '<div class="vcbk-col-4"><div class="vcbk-card"><div class="vcbk-info">'
            . '<span class="vcbk-info-icon"><span class="dashicons dashicons-database"></span></span>'
            . '<div><p class="vcbk-info-title">' . esc_html__('Last Backup', 'virakcloud-backup') . '</p>'
            . '<p class="vcbk-info-value">' . esc_html((string) $last) . '</p></div>'
            . '</div></div></div>';
        // Next run card
        echo '<div class="vcbk-col-4"><div class="vcbk-card"><div class="vcbk-info">'
            . '<span class="vcbk-info-icon"><span class="dashicons dashicons-schedule"></span></span>'
            . '<div><p class="vcbk-info-title">' . esc_html__('Next Scheduled Backup', 'virakcloud-backup') . '</p>'
            . '<p class="vcbk-info-value">' . esc_html((string) $nextText) . '</p></div>'
            . '</div></div></div>';
        // S3 status card
        $s3Badge = '<span class="vcbk-badge warn">' . esc_html__('Not configured', 'virakcloud-backup') . '</span>';
        try {
            (new \VirakCloud\Backup\S3ClientFactory($this->settings, $this->logger))
                ->create()->headBucket(['Bucket' => $this->settings->get()['s3']['bucket']]);
            $s3Badge = '<span class="vcbk-badge ok">OK</span>';
        } catch (\Throwable $e) {
            $s3Badge = '<span class="vcbk-badge err">' . esc_html__('Error', 'virakcloud-backup') . '</span>';
        }
        echo '<div class="vcbk-col-4"><div class="vcbk-card"><div class="vcbk-info">'
            . '<span class="vcbk-info-icon"><span class="dashicons dashicons-cloud"></span></span>'
            . '<div><p class="vcbk-info-title">' . esc_html__('S3 Upload Status', 'virakcloud-backup') . '</p>'
            . '<p class="vcbk-info-value">' . $s3Badge . '</p></div>'
            . '</div></div></div>';
        echo '</div>';
        // CTA
        $backupsUrl = esc_url(admin_url('admin.php?page=vcbk-backups'));
        echo '<div class="vcbk-card"><a class="button button-primary vcbk-btn vcbk-btn--primary" href="' . $backupsUrl . '"><span class="dashicons dashicons-backup"></span>' . esc_html__('Open Backups', 'virakcloud-backup') . '</a></div>';

        // Recent backups (from S3)
        echo '<div class="vcbk-card">';
        echo '<h2 style="margin-top:0">' . esc_html__('Recent Backups', 'virakcloud-backup') . '</h2>';
        $bucket = (string) ($this->settings->get()['s3']['bucket'] ?? '');
        if ($bucket === '') {
            echo '<p class="vcbk-warn vcbk-alert">' . esc_html__('Configure your S3 bucket in Settings to see recent backups.', 'virakcloud-backup') . '</p>';
        } else {
            try {
                $client = (new \VirakCloud\Backup\S3ClientFactory($this->settings, $this->logger))->create();
                $sitePrefix = (new \VirakCloud\Backup\Settings())->sitePrefix();
                $prefix = 'backups/' . $sitePrefix . '/';
                $res = $client->listObjectsV2(['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => 1000]);
                if (empty($res['Contents'])) {
                    // Fallback to legacy prefix without site subdir
                    $res = $client->listObjectsV2(['Bucket' => $bucket, 'Prefix' => 'backups/', 'MaxKeys' => 1000]);
                }
                $items = [];
                foreach ((array) ($res['Contents'] ?? []) as $obj) {
                    $key = (string) $obj['Key'];
                    if (!str_contains($key, 'backup-')) {
                        continue;
                    }
                    $modified = '';
                    if (isset($obj['LastModified']) && $obj['LastModified'] instanceof \DateTimeInterface) {
                        $modified = $obj['LastModified']->format('c');
                    } elseif (isset($obj['LastModified'])) {
                        $modified = (string) $obj['LastModified'];
                    }
                    $items[] = [
                        'key' => $key,
                        'size' => (int) $obj['Size'],
                        'modified' => $modified,
                    ];
                }
                usort(
                    $items,
                    function ($a, $b) {
                        return strcmp($b['key'], $a['key']);
                    }
                );
                $items = array_slice($items, 0, 5);
                if (empty($items)) {
                    echo '<p class="vcbk-muted">' . esc_html__('No backups found yet.', 'virakcloud-backup') . '</p>';
                } else {
                    echo '<table class="vcbk-table"><thead><tr><th>' . esc_html__('File', 'virakcloud-backup') . '</th><th>' . esc_html__('Size', 'virakcloud-backup') . '</th><th>' . esc_html__('Actions', 'virakcloud-backup') . '</th></tr></thead><tbody>';
                    foreach ($items as $it) {
                        $key = $it['key'];
                        $size = size_format((int) $it['size']);
                        $restoreUrl = add_query_arg('key', rawurlencode($key), admin_url('admin.php?page=vcbk-restore'));
                        $dlUrl = wp_nonce_url(
                            add_query_arg('key', rawurlencode($key), admin_url('admin-post.php?action=vcbk_download_backup')),
                            'vcbk_download_backup'
                        );
                        echo '<tr><td>' . esc_html($key) . '</td>'
                            . '<td>' . esc_html((string) $size) . '</td>'
                            . '<td class="vcbk-actions">'
                            . '<a class="button vcbk-btn vcbk-btn--secondary" href="' . esc_url($restoreUrl) . '"><span class="dashicons dashicons-update"></span>' . esc_html__('Restore', 'virakcloud-backup') . '</a> '
                            . '<a class="button vcbk-btn vcbk-btn--secondary" href="' . esc_url($dlUrl) . '"><span class="dashicons dashicons-download"></span>' . esc_html__('Download', 'virakcloud-backup') . '</a>'
                            . '</td></tr>';
                    }
                    echo '</tbody></table>';
                }
            } catch (\Throwable $e) {
                echo '<p class="vcbk-muted">' . esc_html($e->getMessage()) . '</p>';
            }
        }
        echo '</div>';

        // Health (previously on Status page)
        echo '<div class="vcbk-card">';
        echo '<h2 style="margin-top:0">' . esc_html__('Status', 'virakcloud-backup') . '</h2>';
        $tz = wp_timezone();
        $now = new \DateTimeImmutable('now', $tz);
        $last = get_option('vcbk_last_backup');
        $lastTs = is_string($last) ? strtotime($last) : false;
        $lastText = $lastTs !== false
            ? human_time_diff((int) $lastTs, time()) . ' ' . __('ago', 'virakcloud-backup')
            : __('never', 'virakcloud-backup');
        $nextTs = wp_next_scheduled('vcbk_cron_run');
        $nextText = $nextTs ? wp_date('Y-m-d H:i', (int) $nextTs, $tz) : __('not scheduled', 'virakcloud-backup');
        $mem = ini_get('memory_limit');
        $maxExec = ini_get('max_execution_time');
        $free = function_exists('disk_free_space') ? @disk_free_space(WP_CONTENT_DIR) : null;
        $freeText = $free !== false && $free !== null ? size_format((int) $free) : __('unknown', 'virakcloud-backup');
        $s3Ok = false;
        $s3Err = '';
        try {
            (new \VirakCloud\Backup\S3ClientFactory($this->settings, $this->logger))
                ->create()->headBucket(['Bucket' => $this->settings->get()['s3']['bucket']]);
            $s3Ok = true;
        } catch (\Throwable $e) {
            $s3Err = $e->getMessage();
        }
        echo '<table class="vcbk-table" style="max-width:780px">';
        echo '<tbody>';
        $ok = !empty($last);
        echo '<tr><th>' . esc_html__('Last backup', 'virakcloud-backup') . '</th>'
            . '<td>' . esc_html($lastText) . ' ' . ($ok ? '<span class="vcbk-badge ok">OK</span>' : '<span class="vcbk-badge warn">' . esc_html__('Missing', 'virakcloud-backup') . '</span>') . '</td></tr>';

        $ok = (bool) $nextTs;
        echo '<tr><th>' . esc_html__('Next run', 'virakcloud-backup') . '</th>'
            . '<td>' . esc_html((string) $nextText) . ' ' . ($ok ? '<span class="vcbk-badge ok">OK</span>' : '<span class="vcbk-badge warn">' . esc_html__('Not scheduled', 'virakcloud-backup') . '</span>') . '</td></tr>';

        $ok = (int) wp_convert_hr_to_bytes((string) $mem) >= 256 * 1024 * 1024;
        echo '<tr><th>' . esc_html__('PHP memory_limit', 'virakcloud-backup') . '</th>'
            . '<td>' . esc_html((string) $mem) . ' ' . ($ok ? '<span class="vcbk-badge ok">OK</span>' : '<span class="vcbk-badge warn">' . esc_html__('Low', 'virakcloud-backup') . '</span>') . '</td></tr>';

        $ok = (int) $maxExec >= 120;
        echo '<tr><th>' . esc_html__('Max execution time', 'virakcloud-backup') . '</th>'
            . '<td>' . esc_html((string) $maxExec) . ' ' . ($ok ? '<span class="vcbk-badge ok">OK</span>' : '<span class="vcbk-badge warn">' . esc_html__('Low', 'virakcloud-backup') . '</span>') . '</td></tr>';

        $ok = $free !== null && $free !== false && $free > 500 * 1024 * 1024;
        echo '<tr><th>' . esc_html__('Free disk space', 'virakcloud-backup') . '</th>'
            . '<td>' . esc_html((string) $freeText) . ' ' . ($ok ? '<span class="vcbk-badge ok">OK</span>' : '<span class="vcbk-badge warn">' . esc_html__('Low', 'virakcloud-backup') . '</span>') . '</td></tr>';

        echo '<tr><th>' . esc_html__('VirakCloud S3 connectivity', 'virakcloud-backup') . '</th>'
            . '<td>' . ($s3Ok ? '<span class="vcbk-badge ok">OK</span>' : '<span class="vcbk-badge err">' . esc_html($s3Err) . '</span>') . '</td></tr>';
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
    }

    public function renderSettings(): void
    {
        $cfg = $this->settings->get();
        echo '<div class="wrap vcbk-wrap"><h1>' . esc_html__('Settings', 'virakcloud-backup') . '</h1>';
        if (!empty($_GET['message'])) {
            echo '<div class="notice notice-success"><p>' . esc_html((string) $_GET['message']) . '</p></div>';
        }
        if (!empty($_GET['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html((string) $_GET['error']) . '</p></div>';
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('vcbk_save_settings');
        echo '<input type="hidden" name="action" value="vcbk_save_settings" />';
        echo '<h2>' . esc_html__('VirakCloud S3', 'virakcloud-backup') . '</h2>';
        echo '<table class="form-table">';
        $s3 = $cfg['s3'];
        $fields = [
            'endpoint' => 'Endpoint',
            'region' => 'Region',
            'bucket' => 'Bucket',
            'access_key' => 'Access Key',
            'secret_key' => 'Secret Key',
        ];
        foreach ($fields as $key => $label) {
            $val = isset($s3[$key]) ? (string) $s3[$key] : '';
            $type = ($key === 'secret_key') ? 'password' : 'text';
            echo '<tr><th><label>'
                . esc_html__($label, 'virakcloud-backup')
                . '</label></th><td>';
            printf(
                '<input type="%s" name="s3[%s]" value="%s" class="regular-text" />',
                esc_attr($type),
                esc_attr($key),
                esc_attr($val)
            );
            echo '</td></tr>';
        }
        echo '<tr><th>' . esc_html__('Path Style', 'virakcloud-backup') . '</th><td>';
        $pathStyleChecked = checked(!empty($s3['path_style']), true, false);
        printf(
            '<label><input type="checkbox" name="s3[path_style]" %s /> %s</label>',
            $pathStyleChecked,
            esc_html__('Use path-style addressing', 'virakcloud-backup')
        );
        echo '</td></tr>';
        echo '</table>';

        // Schedule & Backup options
        $schedule = $cfg['schedule'];
        $intervals = ['2h','4h','8h','12h','daily','weekly','fortnightly','monthly'];
        echo '<h2 style="margin-top:24px">' . esc_html__('Schedule', 'virakcloud-backup') . '</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Interval', 'virakcloud-backup') . '</th><td><select name="schedule[interval]">';
        foreach ($intervals as $i) {
            $sel = selected($schedule['interval'], $i, false);
            printf('<option value="%s" %s>%s</option>', esc_attr($i), $sel, esc_html($i));
        }
        echo '</select></td></tr>';
        // Scheduled backup type
        $types = ['full','db','files','incremental'];
        echo '<tr><th>' . esc_html__('Backup Type', 'virakcloud-backup') . '</th><td><select name="backup[type]">';
        foreach ($types as $t) {
            printf('<option value="%s" %s>%s</option>', esc_attr($t), selected($cfg['backup']['type'], $t, false), esc_html($t));
        }
        echo '</select></td></tr>';
        // Archive format: always ZIP (no UI)
        echo '<tr><th>' . esc_html__('Start Time', 'virakcloud-backup') . '</th><td>';
        $startVal = esc_attr($schedule['start_time'] ?? '01:30');
        printf('<input type="text" name="schedule[start_time]" value="%s" class="regular-text" placeholder="HH:MM" />', $startVal);
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__('Catch up', 'virakcloud-backup') . '</th><td>';
        $catchupChecked = checked(!empty($schedule['catchup']), true, false);
        printf('<label><input type="checkbox" name="schedule[catchup]" %s /> %s</label>', $catchupChecked, esc_html__('Run missed backups', 'virakcloud-backup'));
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__('Next run (preview)', 'virakcloud-backup') . '</th><td><em id="vcbk-next-run-preview"></em></td></tr>';
        echo '</table>';

        echo '<p><button class="button button-primary vcbk-btn vcbk-btn--primary">' . esc_html__('Save Settings', 'virakcloud-backup') . '</button> ';
        $testUrl = wp_nonce_url(
            admin_url('admin-post.php?action=vcbk_test_s3'),
            'vcbk_test_s3'
        );
        echo '<a class="button vcbk-btn vcbk-btn--secondary" href="' . esc_url($testUrl) . '">'
            . esc_html__('Test S3 Connection', 'virakcloud-backup')
            . '</a></p>';
        echo '</form></div>';
    }

    public function renderSchedules(): void
    {
        $cfg = $this->settings->get();
        $schedule = $cfg['schedule'];
        $intervals = ['2h','4h','8h','12h','daily','weekly','fortnightly','monthly'];
        echo '<div class="wrap vcbk-wrap"><h1>' . esc_html__('Schedules', 'virakcloud-backup') . '</h1>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('vcbk_save_settings');
        echo '<input type="hidden" name="action" value="vcbk_save_settings" />';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Interval', 'virakcloud-backup') . '</th><td><select name="schedule[interval]">';
        foreach ($intervals as $i) {
            $sel = selected($schedule['interval'], $i, false);
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($i),
                $sel,
                esc_html($i)
            );
        }
        echo '</select></td></tr>';
        // Scheduled backup type
        $types = ['full','db','files','incremental'];
        echo '<tr><th>' . esc_html__('Backup Type', 'virakcloud-backup') . '</th><td><select name="backup[type]">';
        foreach ($types as $t) {
            printf('<option value="%s" %s>%s</option>', esc_attr($t), selected($cfg['backup']['type'], $t, false), esc_html($t));
        }
        echo '</select></td></tr>';
        echo '<tr><th>' . esc_html__('Start Time', 'virakcloud-backup') . '</th><td>';
        $startVal = esc_attr($schedule['start_time'] ?? '01:30');
        printf(
            '<input type="text" name="schedule[start_time]" value="%s" class="regular-text" placeholder="HH:MM" />',
            $startVal
        );
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__('Catch up', 'virakcloud-backup') . '</th><td>';
        $catchupChecked = checked(!empty($schedule['catchup']), true, false);
        printf(
            '<label><input type="checkbox" name="schedule[catchup]" %s /> %s</label>',
            $catchupChecked,
            esc_html__('Run missed backups', 'virakcloud-backup')
        );
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__('Next run (preview)', 'virakcloud-backup') . '</th><td><em id="vcbk-next-run-preview"></em></td></tr>';
        echo '</table><p><button class="button button-primary vcbk-btn vcbk-btn--primary">'
            . esc_html__('Save Schedule', 'virakcloud-backup')
            . '</button></p>';
        echo '<p style="color:#555">' . esc_html__('Tip: Configure a real cron to call wp-cron.php every 5 minutes for reliability.', 'virakcloud-backup') . '</p>';
        echo '<pre>*/5 * * * * wget -q -O - ' . esc_html(home_url('/wp-cron.php?doing_wp_cron')) . ' >/dev/null 2>&1</pre>';
        echo '</form></div>';
    }

    public function renderBackups(): void
    {
        echo '<div class="wrap vcbk-wrap"><h1>' . esc_html__('Backups', 'virakcloud-backup') . '</h1>';
        if (!empty($_GET['message'])) {
            echo '<div class="notice notice-success"><p>' . esc_html((string) $_GET['message']) . '</p></div>';
        }
        if (!empty($_GET['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html((string) $_GET['error']) . '</p></div>';
        }
        echo '<div class="vcbk-card">';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('vcbk_run_backup');
        echo '<input type="hidden" name="action" value="vcbk_run_backup" />';
        echo '<label style="margin-right:8px">' . esc_html__('Mode', 'virakcloud-backup') . ' ';
        echo '<select name="type" title="' . esc_attr__('Choose what to include', 'virakcloud-backup') . '">';
        $modes = [
            'full' => __('Database + files', 'virakcloud-backup'),
            'db' => __('Database only', 'virakcloud-backup'),
            'files' => __('wp-content only', 'virakcloud-backup'),
            'incremental' => __('Changed since last backup', 'virakcloud-backup'),
        ];
        foreach ($modes as $val => $desc) {
            printf('<option value="%s" title="%s">%s</option>', esc_attr($val), esc_attr($desc), esc_html($val));
        }
        echo '</select></label> ';
        echo '<label class="vcbk-switch" style="margin-left:10px">';
        echo '<input type="checkbox" name="upload_to_s3" />';
        echo '<span class="vcbk-switch-slider" aria-hidden="true"></span>';
        echo '<span class="vcbk-switch-label">' . esc_html__('Upload to S3 after backup', 'virakcloud-backup') . '</span>';
        echo '</label> ';
        echo '<button class="button button-primary vcbk-btn vcbk-btn--primary" style="margin-left:10px"><span class="dashicons dashicons-backup"></span>' . esc_html__('Run Backup', 'virakcloud-backup') . '</button>';
        echo '</form>';
        echo '<p class="vcbk-muted" style="margin-top:8px;max-width:780px">'
            . '<strong>' . esc_html__('Types:', 'virakcloud-backup') . '</strong> '
            . esc_html__('full = database + all WordPress files (core, wp-content). ', 'virakcloud-backup')
            . esc_html__('db = only the database export (.sql). ', 'virakcloud-backup')
            . esc_html__('files = only wp-content (themes, plugins, uploads). ', 'virakcloud-backup')
            . esc_html__('incremental = database + files changed since the last backup.', 'virakcloud-backup')
            . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px">';
        wp_nonce_field('vcbk_run_test');
        echo '<input type="hidden" name="action" value="vcbk_run_test" />';
        echo '<button class="button vcbk-btn vcbk-btn--secondary">' . esc_html__('Run Test Backup', 'virakcloud-backup') . '</button>';
        echo '</form>';
        // Upload a backup file to S3 for safekeeping (drag & drop)
        echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px">';
        wp_nonce_field('vcbk_upload_backup');
        echo '<input type="hidden" name="action" value="vcbk_upload_backup" />';
        echo '<div class="vcbk-dropzone" tabindex="0">';
        echo '<input type="file" name="backup_file" accept=".zip,.tar,.gz" />';
        echo '<div class="vcbk-dz-title">' . esc_html__('Upload backup to S3', 'virakcloud-backup') . '</div>';
        echo '<div class="vcbk-dz-sub">' . esc_html__('Drag & drop or click to select a backup file (.zip/.tar/.gz)', 'virakcloud-backup') . '</div>';
        echo '<div class="vcbk-dz-file"></div>';
        echo '<div class="vcbk-dz-progress"><div class="bar"></div></div>';
        echo '</div>';
        echo '<p style="margin-top:10px"><button class="button vcbk-btn vcbk-btn--secondary">' . esc_html__('Upload', 'virakcloud-backup') . '</button></p>';
        echo '</form>';
        echo '</div>';
        $test = get_option('vcbk_last_test');
        if (is_array($test)) {
            echo '<div class="vcbk-alert vcbk-ok">';
            echo '<strong>' . esc_html__('Last Test Result', 'virakcloud-backup') . ':</strong> ';
            printf(
                esc_html__('Latency %d ms, size %d bytes, checksum %s', 'virakcloud-backup'),
                (int) ($test['latency_ms'] ?? 0),
                (int) ($test['size'] ?? 0),
                esc_html((string) ($test['sha256'] ?? ''))
            );
            echo '</div>';
        }
        // Progress + live logs
        echo '<div class="vcbk-card">';
        echo '<h2 style="margin-top:0">' . esc_html__('Progress', 'virakcloud-backup') . '</h2>';
        echo '<div id="vcbk-progress" class="vcbk-progress"><div id="vcbk-progress-bar" class="vcbk-progress-bar"></div></div>';
        echo '<div id="vcbk-stages" class="vcbk-stages">';
        echo '<div class="vcbk-stage" data-step="archive">' . esc_html__('Archiving', 'virakcloud-backup') . '</div>';
        echo '<div class="vcbk-stage" data-step="upload">' . esc_html__('Uploading', 'virakcloud-backup') . '</div>';
        echo '<div class="vcbk-stage" data-step="complete">' . esc_html__('Complete', 'virakcloud-backup') . '</div>';
        echo '</div>';
        echo '<p id="vcbk-progress-stage" class="vcbk-muted" style="margin-top:6px"></p>';
        echo '<p class="vcbk-actions">';
        echo '<button class="button vcbk-btn vcbk-btn--secondary" id="vcbk-pause"><span class="dashicons dashicons-controls-pause"></span>' . esc_html__('Pause', 'virakcloud-backup') . '</button> ';
        echo '<button class="button vcbk-btn vcbk-btn--secondary" id="vcbk-resume"><span class="dashicons dashicons-controls-play"></span>' . esc_html__('Resume', 'virakcloud-backup') . '</button> ';
        echo '<button class="button vcbk-btn vcbk-btn--secondary" id="vcbk-cancel"><span class="dashicons dashicons-dismiss"></span>' . esc_html__('Cancel', 'virakcloud-backup') . '</button>';
        echo '</p>';
        echo '</div>';

        echo '<div class="vcbk-card">';
        echo '<h2 style="margin-top:0">' . esc_html__('Live Logs', 'virakcloud-backup') . '</h2>';
        $dlUrl = wp_nonce_url(admin_url('admin-post.php?action=vcbk_download_log'), 'vcbk_download_log');
        echo '<div class="vcbk-log-toolbar">';
        echo '<div class="vcbk-tabs" role="tablist">';
        echo '<button type="button" class="vcbk-tab" data-level="" aria-selected="true">' . esc_html__('All', 'virakcloud-backup') . '</button>';
        echo '<button type="button" class="vcbk-tab" data-level="info" aria-selected="false">' . esc_html__('Info', 'virakcloud-backup') . '</button>';
        echo '<button type="button" class="vcbk-tab" data-level="debug" aria-selected="false">' . esc_html__('Debug', 'virakcloud-backup') . '</button>';
        echo '<button type="button" class="vcbk-tab" data-level="error" aria-selected="false">' . esc_html__('Errors', 'virakcloud-backup') . '</button>';
        echo '</div>';
        echo '<div style="margin-left:auto" class="vcbk-actions">';
        echo '<button type="button" class="button vcbk-btn vcbk-btn--sm" id="vcbk-toggle-autorefresh">' . esc_html__('Start Auto-Refresh', 'virakcloud-backup') . '</button> ';
        echo '<button type="button" class="button vcbk-btn vcbk-btn--sm" id="vcbk-toggle-scroll">' . esc_html__('Stop Auto-Scroll', 'virakcloud-backup') . '</button> ';
        echo '<button type="button" class="button vcbk-btn vcbk-btn--sm" id="vcbk-copy-log">' . esc_html__('Copy', 'virakcloud-backup') . '</button> ';
        echo '<a class="button vcbk-btn vcbk-btn--sm" href="' . esc_url($dlUrl) . '">' . esc_html__('Download', 'virakcloud-backup') . '</a>';
        echo '</div>';
        echo '</div>';
        echo '<div class="vcbk-collapsible">';
        echo '<div class="vcbk-collapsible-header" role="button" aria-expanded="true">' . esc_html__('Toggle Logs', 'virakcloud-backup') . '</div>';
        echo '<div class="vcbk-collapsible-content">';
        echo '<pre id="vcbk-log" class="vcbk-log"></pre>';
        echo '</div></div>';
        echo '</div>';
        echo '</div>';
    }

    public function renderRestore(): void
    {
        echo '<div class="wrap vcbk-wrap"><h1>' . esc_html__('Restore', 'virakcloud-backup') . '</h1>';
        if (!empty($_GET['message'])) {
            echo '<div class="notice notice-success"><p>' . esc_html((string) $_GET['message']) . '</p></div>';
        }
        if (!empty($_GET['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html((string) $_GET['error']) . '</p></div>';
        }
        echo '<div class="vcbk-card">';
        echo '<p>'
            . esc_html__(
                'Pick a backup from your VirakCloud S3 bucket. We will restore the database and wp-content files. '
                . 'This may overwrite existing data.',
                'virakcloud-backup'
            )
            . '</p>';
        $s3 = $this->settings->get()['s3'] ?? [];
        $bucket = (string) ($s3['bucket'] ?? '');
        if ($bucket === '') {
            echo '<div class="vcbk-card"><p class="vcbk-warn vcbk-alert">' . esc_html__('S3 bucket is not configured. Please set it in Settings.', 'virakcloud-backup') . '</p></div>';
            echo '</div>';
            return;
        }
        $access = (string) ($s3['access_key'] ?? '');
        $secret = (string) ($s3['secret_key'] ?? '');
        if ($access === '' || $secret === '') {
            echo '<div class="vcbk-card"><p class="vcbk-warn vcbk-alert">'
                . esc_html__('S3 access/secret keys are not configured. Please set them in Settings before restoring.', 'virakcloud-backup')
                . '</p></div>';
            echo '</div>';
            return;
        }
        try {
            $client = (new S3ClientFactory($this->settings, $this->logger))->create();
            $sitePrefix = (new \VirakCloud\Backup\Settings())->sitePrefix();
            $prefix = 'backups/' . $sitePrefix . '/';
            $items = [];
            $params = ['Bucket' => $bucket, 'Prefix' => $prefix, 'MaxKeys' => 1000];
            do {
                $res = $client->listObjectsV2($params);
                foreach ((array) ($res['Contents'] ?? []) as $obj) {
                    $key = (string) $obj['Key'];
                    if (str_contains($key, 'backup-') && (str_ends_with($key, '.zip') || str_ends_with($key, '.tar.gz'))) {
                        $items[] = [
                            'key' => $key,
                            'size' => (int) $obj['Size'],
                            'modified' => (string) ($obj['LastModified']->format('c') ?? ''),
                        ];
                    }
                }
                $params['ContinuationToken'] = isset($res['IsTruncated']) && $res['IsTruncated'] ? $res['NextContinuationToken'] : null;
            } while (!empty($params['ContinuationToken']));
            if (empty($items)) {
                // Fallback to legacy flat prefix
                $params = ['Bucket' => $bucket, 'Prefix' => 'backups/', 'MaxKeys' => 1000];
                do {
                    $res = $client->listObjectsV2($params);
                    foreach ((array) ($res['Contents'] ?? []) as $obj) {
                        $key = (string) $obj['Key'];
                        if (str_contains($key, 'backup-') && (str_ends_with($key, '.zip') || str_ends_with($key, '.tar.gz'))) {
                            $items[] = [
                                'key' => $key,
                                'size' => (int) $obj['Size'],
                                'modified' => (string) ($obj['LastModified']->format('c') ?? ''),
                            ];
                        }
                    }
                    $params['ContinuationToken'] = isset($res['IsTruncated']) && $res['IsTruncated'] ? $res['NextContinuationToken'] : null;
                } while (!empty($params['ContinuationToken']));
            }
            usort(
                $items,
                function ($a, $b) {
                    return strcmp($b['key'], $a['key']);
                }
            );

            $selectedKey = isset($_GET['key']) ? (string) $_GET['key'] : '';
            if (empty($items)) {
                echo '<p class="vcbk-warn vcbk-alert">' . esc_html__('No backups found for this site.', 'virakcloud-backup') . '</p>';
            } else {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('vcbk_run_restore');
                echo '<input type="hidden" name="action" value="vcbk_run_restore" />';
                echo '<label style="display:block;margin-bottom:6px">' . esc_html__('Backup', 'virakcloud-backup') . '</label>';
                echo '<select name="key" class="regular-text" style="min-width:420px">';
                foreach ($items as $it) {
                    $label = $it['key'] . ' (' . size_format($it['size']) . ')';
                    $sel = selected($selectedKey, $it['key'], false);
                    printf('<option value="%s" %s>%s</option>', esc_attr($it['key']), $sel, esc_html($label));
                }
                echo '</select>';
                echo '<div style="margin-top:10px;display:flex;gap:14px;flex-wrap:wrap">';
                echo '<label class="vcbk-switch">';
                echo '<input type="checkbox" name="dry_run" />';
                echo '<span class="vcbk-switch-slider" aria-hidden="true"></span>';
                echo '<span class="vcbk-switch-label">' . esc_html__('Dry Run (extract and validate only)', 'virakcloud-backup') . '</span>';
                echo '</label>';
                echo '<label class="vcbk-switch">';
                echo '<input type="checkbox" name="full_site" />';
                echo '<span class="vcbk-switch-slider" aria-hidden="true"></span>';
                echo '<span class="vcbk-switch-label">' . esc_html__('Full site restore (replace WordPress core and wp-config.php)', 'virakcloud-backup') . '</span>';
                echo '</label>';
                echo '</div>';
                // URL rewrite (migration) options
                $home = home_url();
                echo '<div style="margin-top:10px">' . esc_html__('Rewrite URLs', 'virakcloud-backup') . ':</div>';
                echo '<div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:6px">';
                echo '<label>' . esc_html__('From', 'virakcloud-backup') . '<br />';
                printf('<input type="url" name="migrate[from]" value="%s" class="vcbk-input" placeholder="https://old.example.com" data-validate="url" />', esc_attr($home));
                echo '<span class="vcbk-inline-help"></span>';
                echo '</label>';
                echo '<label>' . esc_html__('To', 'virakcloud-backup') . '<br />';
                echo '<input type="url" name="migrate[to]" value="" class="vcbk-input" placeholder="https://new.example.com" data-validate="url" />';
                echo '<span class="vcbk-inline-help"></span>';
                echo '</label>';
                echo '</div>';
                echo '<button class="button button-primary vcbk-btn vcbk-btn--primary" style="margin-left:0;margin-top:12px"><span class="dashicons dashicons-update"></span>';
                echo esc_html__('Start Restore', 'virakcloud-backup');
                echo '</button>';
                echo '</form>';
                // Upload-and-restore form
                echo '<form method="post" enctype="multipart/form-data" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:12px">';
                wp_nonce_field('vcbk_upload_restore');
                echo '<input type="hidden" name="action" value="vcbk_upload_restore" />';
                echo '<div class="vcbk-dropzone" tabindex="0">';
                echo '<input type="file" name="backup_file" accept=".zip,.tar,.gz" />';
                echo '<div class="vcbk-dz-title">' . esc_html__('Upload backup', 'virakcloud-backup') . '</div>';
                echo '<div class="vcbk-dz-sub">' . esc_html__('Drag & drop or click to select a backup file to restore (.zip/.tar/.gz)', 'virakcloud-backup') . '</div>';
                echo '<div class="vcbk-dz-file"></div>';
                echo '<div class="vcbk-dz-progress"><div class="bar"></div></div>';
                echo '</div>';
                echo '<div style="margin-top:10px;display:flex;gap:10px;flex-wrap:wrap">';
                echo '<label class="vcbk-switch"><input type="checkbox" name="dry_run" /><span class="vcbk-switch-slider" aria-hidden="true"></span><span class="vcbk-switch-label">' . esc_html__('Dry Run', 'virakcloud-backup') . '</span></label> ';
                echo '<label class="vcbk-switch"><input type="checkbox" name="full_site" /><span class="vcbk-switch-slider" aria-hidden="true"></span><span class="vcbk-switch-label">' . esc_html__('Full site restore', 'virakcloud-backup') . '</span></label> ';
                echo '<button class="button vcbk-btn vcbk-btn--secondary"><span class="dashicons dashicons-upload"></span>' . esc_html__('Upload and Restore', 'virakcloud-backup') . '</button>';
                echo '</div>';
                echo '</form>';
            }
        } catch (\Throwable $e) {
            echo '<div class="notice notice-error"><p>' . esc_html($e->getMessage()) . '</p></div>';
        }
        echo '</div>';

        echo '<div class="vcbk-card">';
        echo '<h2 style="margin-top:0">' . esc_html__('Progress', 'virakcloud-backup') . '</h2>';
        echo '<div id="vcbk-progress" class="vcbk-progress"><div id="vcbk-progress-bar" class="vcbk-progress-bar"></div></div>';
        echo '<div id="vcbk-stages" class="vcbk-stages">';
        echo '<div class="vcbk-stage" data-step="archive">' . esc_html__('Archiving', 'virakcloud-backup') . '</div>';
        echo '<div class="vcbk-stage" data-step="upload">' . esc_html__('Uploading', 'virakcloud-backup') . '</div>';
        echo '<div class="vcbk-stage" data-step="complete">' . esc_html__('Complete', 'virakcloud-backup') . '</div>';
        echo '</div>';
        echo '<p id="vcbk-progress-stage" class="vcbk-muted" style="margin-top:6px"></p>';
        echo '<div class="vcbk-log-toolbar">';
        echo '<div class="vcbk-tabs" role="tablist">';
        echo '<button type="button" class="vcbk-tab" data-level="" aria-selected="true">' . esc_html__('All', 'virakcloud-backup') . '</button>';
        echo '<button type="button" class="vcbk-tab" data-level="info" aria-selected="false">' . esc_html__('Info', 'virakcloud-backup') . '</button>';
        echo '<button type="button" class="vcbk-tab" data-level="debug" aria-selected="false">' . esc_html__('Debug', 'virakcloud-backup') . '</button>';
        echo '<button type="button" class="vcbk-tab" data-level="error" aria-selected="false">' . esc_html__('Errors', 'virakcloud-backup') . '</button>';
        echo '</div>';
        echo '<div style="margin-left:auto" class="vcbk-actions">';
        echo '<button type="button" class="button vcbk-btn vcbk-btn--sm" id="vcbk-toggle-autorefresh">' . esc_html__('Start Auto-Refresh', 'virakcloud-backup') . '</button> ';
        echo '<button type="button" class="button vcbk-btn vcbk-btn--sm" id="vcbk-toggle-scroll">' . esc_html__('Stop Auto-Scroll', 'virakcloud-backup') . '</button> ';
        echo '<button type="button" class="button vcbk-btn vcbk-btn--sm" id="vcbk-copy-log">' . esc_html__('Copy', 'virakcloud-backup') . '</button> ';
        $dlUrl = wp_nonce_url(admin_url('admin-post.php?action=vcbk_download_log'), 'vcbk_download_log');
        echo '<a class="button vcbk-btn vcbk-btn--sm" href="' . esc_url($dlUrl) . '">' . esc_html__('Download', 'virakcloud-backup') . '</a>';
        echo '</div>';
        echo '</div>';
        echo '<div class="vcbk-collapsible">';
        echo '<div class="vcbk-collapsible-header" role="button" aria-expanded="true">' . esc_html__('Toggle Logs', 'virakcloud-backup') . '</div>';
        echo '<div class="vcbk-collapsible-content">';
        echo '<pre id="vcbk-log" class="vcbk-log"></pre>';
        echo '</div></div>';
        echo '</div>';

        echo '</div>';
    }

    public function handleRunRestore(): void
    {
        check_admin_referer('vcbk_run_restore');
        if (!current_user_can('update_core')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        $key = isset($_POST['key']) ? sanitize_text_field((string) $_POST['key']) : '';
        $dry = !empty($_POST['dry_run']);
        $full = !empty($_POST['full_site']);
        // Auto-detect full-site archive by filename if checkbox not ticked
        if (!$full && preg_match('/backup-\s*full-?/i', basename($key))) {
            $full = true;
        }
        $migrate = isset($_POST['migrate']) && is_array($_POST['migrate']) ? (array) $_POST['migrate'] : [];
        if ($key === '') {
            wp_safe_redirect(add_query_arg('error', rawurlencode(__('Missing backup key', 'virakcloud-backup')), admin_url('admin.php?page=vcbk-restore')));
            exit;
        }
        try {
            $rm = new RestoreManager($this->settings, $this->logger);
            $options = ['dry_run' => $dry];
            $from = isset($migrate['from']) ? esc_url_raw((string) $migrate['from']) : '';
            $to = isset($migrate['to']) ? esc_url_raw((string) $migrate['to']) : '';
            if ($from !== '' && $to !== '' && $from !== $to && !$dry) {
                $options['migrate'] = ['from' => $from, 'to' => $to];
            }
            if ($full && !$dry) {
                $rm->restoreFullFromS3($key, ['preserve_plugin' => true] + $options);
                $msg = __('Full restore complete', 'virakcloud-backup');
            } else {
                $rm->restoreFromS3($key, $options);
                $msg = $dry
                    ? __('Dry run complete', 'virakcloud-backup')
                    : __('Restore complete. Please review S3 Settings and ensure your access/secret keys are configured to resume backups.', 'virakcloud-backup');
            }
            wp_safe_redirect(add_query_arg('message', rawurlencode($msg), admin_url('admin.php?page=vcbk-restore')));
        } catch (\Throwable $e) {
            wp_safe_redirect(add_query_arg('error', rawurlencode($e->getMessage()), admin_url('admin.php?page=vcbk-restore')));
        }
        exit;
    }


    public function handleWizardNext(): void
    {
        // Legacy wizard removed.
    }

    public function renderWizard(): void
    {
        // Legacy wizard removed.
    }

    public function renderLogs(): void
    {
        echo '<div class="wrap vcbk-wrap"><h1>' . esc_html__('Logs', 'virakcloud-backup') . '</h1>';
        $level = isset($_GET['level']) ? sanitize_text_field((string) $_GET['level']) : '';
        $dlUrl = wp_nonce_url(admin_url('admin-post.php?action=vcbk_download_log'), 'vcbk_download_log');
        echo '<div class="vcbk-card">';
        echo '<div class="vcbk-log-toolbar">';
        echo '<div class="vcbk-tabs" role="tablist">';
        $tabs = [
            ['','All'], ['info','Info'], ['debug','Debug'], ['error','Errors']
        ];
        foreach ($tabs as $t) {
            $is = ($level === $t[0]) ? 'true' : 'false';
            printf('<button type="button" class="vcbk-tab" data-level="%s" aria-selected="%s">%s</button>', esc_attr($t[0]), esc_attr($is), esc_html__($t[1], 'virakcloud-backup'));
        }
        echo '</div>';
        echo '<div style="margin-left:auto" class="vcbk-actions">';
        echo '<button type="button" class="button vcbk-btn vcbk-btn--sm" id="vcbk-toggle-autorefresh">' . esc_html__('Start Auto-Refresh', 'virakcloud-backup') . '</button> ';
        echo '<button type="button" class="button vcbk-btn vcbk-btn--sm" id="vcbk-copy-log">' . esc_html__('Copy', 'virakcloud-backup') . '</button> ';
        echo '<a class="button vcbk-btn vcbk-btn--sm" href="' . esc_url($dlUrl) . '">' . esc_html__('Download', 'virakcloud-backup') . '</a>';
        echo '</div>';
        echo '</div>';
        echo '<div class="vcbk-collapsible">';
        echo '<div class="vcbk-collapsible-header" role="button" aria-expanded="true">' . esc_html__('Toggle Logs', 'virakcloud-backup') . '</div>';
        echo '<div class="vcbk-collapsible-content">';
        echo '<pre id="vcbk-log" class="vcbk-log"></pre>';
        echo '</div></div>';
        echo '</div>';
    }

    public function handleDownloadLog(): void
    {
        check_admin_referer('vcbk_download_log');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        $file = $this->logger->latestLogFile();
        if (!$file) {
            wp_die(__('No log file found', 'virakcloud-backup'));
        }
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . basename($file) . '"');
        header('Content-Length: ' . (string) filesize($file));
        readfile($file);
        exit;
    }

    public function handleDownloadBackup(): void
    {
        check_admin_referer('vcbk_download_backup');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        $key = isset($_GET['key']) ? sanitize_text_field((string) $_GET['key']) : '';
        if ($key === '') {
            wp_safe_redirect(add_query_arg('error', rawurlencode(__('Missing key', 'virakcloud-backup')), admin_url('admin.php?page=vcbk-restore')));
            exit;
        }
        try {
            $client = (new S3ClientFactory($this->settings, $this->logger))->create();
            $bucket = $this->settings->get()['s3']['bucket'];
            $cmd = $client->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);
            $req = $client->createPresignedRequest($cmd, '+30 minutes');
            wp_safe_redirect((string) $req->getUri());
        } catch (\Throwable $e) {
            wp_safe_redirect(add_query_arg('error', rawurlencode($e->getMessage()), admin_url('admin.php?page=vcbk-restore')));
        }
        exit;
    }

    public function handleUploadBackup(): void
    {
        check_admin_referer('vcbk_upload_backup');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        if (empty($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
            wp_safe_redirect(add_query_arg('error', rawurlencode(__('Missing file', 'virakcloud-backup')), admin_url('admin.php?page=vcbk-backups')));
            exit;
        }
        $file = $_FILES['backup_file'];
        if (!empty($file['error'])) {
            wp_safe_redirect(add_query_arg('error', rawurlencode(__('Upload failed', 'virakcloud-backup')), admin_url('admin.php?page=vcbk-backups')));
            exit;
        }
        $tmp = $file['tmp_name'];
        $name = sanitize_file_name((string) ($file['name'] ?? 'backup-upload.zip'));
        $sitePrefix = (new \VirakCloud\Backup\Settings())->sitePrefix();
        $key = 'backups/' . $sitePrefix . '/' . $name;
        try {
            $client = (new S3ClientFactory($this->settings, $this->logger))->create();
            $bucket = $this->settings->get()['s3']['bucket'];
            $client->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => fopen($tmp, 'rb'),
                // Set a sensible ContentType so browsers don't try to auto-decompress
                'ContentType' => (function (string $name): string {
                    $ln = strtolower($name);
                    if (str_ends_with($ln, '.zip')) {
                        return 'application/zip';
                    }
                    if (str_ends_with($ln, '.tar.gz') || str_ends_with($ln, '.tgz')) {
                        return 'application/gzip';
                    }
                    if (str_ends_with($ln, '.json')) {
                        return 'application/json';
                    }
                    return 'application/octet-stream';
                })($name),
                'ContentEncoding' => 'identity',
            ]);
            @unlink($tmp);
            $msg = sprintf('%s %s', __('Uploaded', 'virakcloud-backup'), $key);
            wp_safe_redirect(add_query_arg('message', rawurlencode($msg), admin_url('admin.php?page=vcbk-backups')));
        } catch (\Throwable $e) {
            wp_safe_redirect(add_query_arg('error', rawurlencode($e->getMessage()), admin_url('admin.php?page=vcbk-backups')));
        }
        exit;
    }

    public function handleUploadRestore(): void
    {
        check_admin_referer('vcbk_upload_restore');
        if (!current_user_can('update_core')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        if (empty($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
            wp_safe_redirect(add_query_arg('error', rawurlencode(__('Missing file', 'virakcloud-backup')), admin_url('admin.php?page=vcbk-restore')));
            exit;
        }
        $file = $_FILES['backup_file'];
        if (!empty($file['error'])) {
            wp_safe_redirect(add_query_arg('error', rawurlencode(__('Upload failed', 'virakcloud-backup')), admin_url('admin.php?page=vcbk-restore')));
            exit;
        }
        $tmp = $file['tmp_name'];
        $name = sanitize_file_name((string) ($file['name'] ?? 'backup-upload.zip'));
        $upload = wp_get_upload_dir();
        $restoreDir = trailingslashit($upload['basedir']) . 'virakcloud-backup/restore';
        wp_mkdir_p($restoreDir);
        $dest = trailingslashit($restoreDir) . $name;
        @rename($tmp, $dest);
        $dry = !empty($_POST['dry_run']);
        $full = !empty($_POST['full_site']);
        try {
            $rm = new RestoreManager($this->settings, $this->logger);
            if ($full && !$dry) {
                $rm->restoreFullLocal($dest, ['preserve_plugin' => true]);
                $msg = __('Full restore complete', 'virakcloud-backup');
            } else {
                $rm->restoreLocal($dest, ['dry_run' => $dry]);
                $msg = $dry ? __('Dry run complete', 'virakcloud-backup') : __('Restore complete.', 'virakcloud-backup');
            }
            wp_safe_redirect(add_query_arg('message', rawurlencode($msg), admin_url('admin.php?page=vcbk-restore')));
        } catch (\Throwable $e) {
            wp_safe_redirect(add_query_arg('error', rawurlencode($e->getMessage()), admin_url('admin.php?page=vcbk-restore')));
        }
        exit;
    }

    public function ajaxTailLogs(): void
    {
        check_ajax_referer('vcbk_ui');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden']);
        }
        $level = isset($_GET['level']) ? sanitize_text_field((string) $_GET['level']) : '';
        if ($level === '') {
            $level = isset($_POST['level']) ? sanitize_text_field((string) $_POST['level']) : '';
        }
        $limit = isset($_GET['lines']) ? (int) $_GET['lines'] : (isset($_POST['lines']) ? (int) $_POST['lines'] : 10);
        if ($limit < 1) {
            $limit = 1;
        }
        if ($limit > 200) {
            $limit = 200;
        }
        $lines = $level !== '' ? $this->logger->tailFiltered($limit, $level) : $this->logger->tail($limit);
        wp_send_json(['lines' => $lines]);
    }

    public function ajaxProgress(): void
    {
        check_ajax_referer('vcbk_ui');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden']);
        }
        wp_send_json($this->logger->getProgress());
    }

    public function ajaxJobControl(): void
    {
        check_ajax_referer('vcbk_ui');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden']);
        }
        $cmd = isset($_POST['cmd']) ? sanitize_text_field((string) $_POST['cmd']) : '';
        if (in_array($cmd, ['pause', 'cancel'], true)) {
            update_option('vcbk_job_control', $cmd, false);
            $this->logger->info('job_control', ['cmd' => $cmd]);
        } elseif ($cmd === 'resume') {
            delete_option('vcbk_job_control');
            $this->logger->info('job_control', ['cmd' => 'resume']);
        }
        wp_send_json_success(['cmd' => $cmd]);
    }

    public function renderStatus(): void
    {
        // Status merged into dashboard. Intentionally left blank.
    }
}
