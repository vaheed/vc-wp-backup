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
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_vcbk_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_vcbk_run_backup', [$this, 'handle_run_backup']);
        add_action('admin_post_vcbk_test_s3', [$this, 'handle_test_s3']);
    }

    public function menu(): void
    {
        add_menu_page(
            __('VirakCloud Backup', 'virakcloud-backup'),
            __('VirakCloud Backup', 'virakcloud-backup'),
            'manage_options',
            'vcbk',
            [$this, 'render_dashboard'],
            'dashicons-cloud'
        );
        add_submenu_page('vcbk', __('Settings', 'virakcloud-backup'), __('Settings', 'virakcloud-backup'), 'manage_options', 'vcbk-settings', [$this, 'render_settings']);
        add_submenu_page('vcbk', __('Schedules', 'virakcloud-backup'), __('Schedules', 'virakcloud-backup'), 'manage_options', 'vcbk-schedules', [$this, 'render_schedules']);
        add_submenu_page('vcbk', __('Backups', 'virakcloud-backup'), __('Backups', 'virakcloud-backup'), 'manage_options', 'vcbk-backups', [$this, 'render_backups']);
        add_submenu_page('vcbk', __('Restore', 'virakcloud-backup'), __('Restore', 'virakcloud-backup'), 'update_core', 'vcbk-restore', [$this, 'render_restore']);
        add_submenu_page('vcbk', __('Migrate', 'virakcloud-backup'), __('Migrate', 'virakcloud-backup'), 'update_core', 'vcbk-migrate', [$this, 'render_migrate']);
        add_submenu_page('vcbk', __('Logs', 'virakcloud-backup'), __('Logs', 'virakcloud-backup'), 'manage_options', 'vcbk-logs', [$this, 'render_logs']);
        add_submenu_page('vcbk', __('Status', 'virakcloud-backup'), __('Status', 'virakcloud-backup'), 'manage_options', 'vcbk-status', [$this, 'render_status']);
    }

    public function register_settings(): void
    {
        register_setting('vcbk', 'vcbk_settings');
    }

    public function handle_save_settings(): void
    {
        check_admin_referer('vcbk_save_settings');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        $data = wp_unslash($_POST);
        $config = $this->settings->sanitize_from_post($data);
        $this->settings->set($config);
        wp_safe_redirect(add_query_arg('updated', '1', wp_get_referer() ?: admin_url('admin.php?page=vcbk-settings')));
        exit;
    }

    public function handle_run_backup(): void
    {
        check_admin_referer('vcbk_run_backup');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        $type = isset($_POST['type']) ? sanitize_text_field((string) $_POST['type']) : 'full';
        $bm = new BackupManager($this->settings, $this->logger);
        try {
            $bm->run($type, ['schedule' => false]);
            $msg = __('Backup started. Check logs for progress.', 'virakcloud-backup');
            wp_safe_redirect(add_query_arg('message', rawurlencode($msg), admin_url('admin.php?page=vcbk-backups')));
        } catch (\Throwable $e) {
            $msg = __('Backup failed: ', 'virakcloud-backup') . $e->getMessage();
            wp_safe_redirect(add_query_arg('error', rawurlencode($msg), admin_url('admin.php?page=vcbk-backups')));
        }
        exit;
    }

    public function handle_test_s3(): void
    {
        check_admin_referer('vcbk_test_s3');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        $client = (new S3ClientFactory($this->settings))->create();
        try {
            $client->headBucket(['Bucket' => $this->settings->get()['s3']['bucket']]);
            $msg = __('S3 connection success!', 'virakcloud-backup');
            wp_safe_redirect(add_query_arg('message', rawurlencode($msg), admin_url('admin.php?page=vcbk-settings')));
        } catch (\Throwable $e) {
            $msg = __('S3 connection failed: ', 'virakcloud-backup') . $e->getMessage();
            wp_safe_redirect(add_query_arg('error', rawurlencode($msg), admin_url('admin.php?page=vcbk-settings')));
        }
        exit;
    }

    public function render_dashboard(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('VirakCloud Backup', 'virakcloud-backup') . '</h1>';
        echo '<p>' . esc_html__('Last backup:', 'virakcloud-backup') . ' ' . esc_html(get_option('vcbk_last_backup', '—')) . '</p>';
        echo '<p><a class="button button-primary" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=vcbk_run_backup'), 'vcbk_run_backup')) . '">' . esc_html__('Run Backup Now', 'virakcloud-backup') . '</a></p>';
        echo '</div>';
    }

    public function render_settings(): void
    {
        $cfg = $this->settings->get();
        echo '<div class="wrap"><h1>' . esc_html__('Settings', 'virakcloud-backup') . '</h1>';
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
            echo '<tr><th><label>' . esc_html__($label, 'virakcloud-backup') . '</label></th><td>';
            printf('<input type="%s" name="s3[%s]" value="%s" class="regular-text" />', esc_attr($type), esc_attr($key), esc_attr($val));
            echo '</td></tr>';
        }
        echo '<tr><th>' . esc_html__('Path Style', 'virakcloud-backup') . '</th><td>';
        printf('<label><input type="checkbox" name="s3[path_style]" %s /> %s</label>', checked(!empty($s3['path_style']), true, false), esc_html__('Use path-style addressing', 'virakcloud-backup'));
        echo '</td></tr>';
        echo '</table>';
        echo '<p><button class="button">' . esc_html__('Save Settings', 'virakcloud-backup') . '</button> ';
        echo '<a class="button" href="' . esc_url(wp_nonce_url(admin_url('admin-post.php?action=vcbk_test_s3'), 'vcbk_test_s3')) . '">' . esc_html__('Test S3 Connection', 'virakcloud-backup') . '</a></p>';
        echo '</form></div>';
    }

    public function render_schedules(): void
    {
        $cfg = $this->settings->get();
        $schedule = $cfg['schedule'];
        $intervals = ['2h','4h','8h','12h','daily','weekly','fortnightly','monthly'];
        echo '<div class="wrap"><h1>' . esc_html__('Schedules', 'virakcloud-backup') . '</h1>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('vcbk_save_settings');
        echo '<input type="hidden" name="action" value="vcbk_save_settings" />';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('Interval', 'virakcloud-backup') . '</th><td><select name="schedule[interval]">';
        foreach ($intervals as $i) {
            printf('<option value="%s" %s>%s</option>', esc_attr($i), selected($schedule['interval'], $i, false), esc_html($i));
        }
        echo '</select></td></tr>';
        echo '<tr><th>' . esc_html__('Start Time', 'virakcloud-backup') . '</th><td>';
        printf('<input type="text" name="schedule[start_time]" value="%s" class="regular-text" placeholder="HH:MM" />', esc_attr($schedule['start_time'] ?? '01:30'));
        echo '</td></tr>';
        echo '<tr><th>' . esc_html__('Catch up', 'virakcloud-backup') . '</th><td>';
        printf('<label><input type="checkbox" name="schedule[catchup]" %s /> %s</label>', checked(!empty($schedule['catchup']), true, false), esc_html__('Run missed backups', 'virakcloud-backup'));
        echo '</td></tr>';
        echo '</table><p><button class="button button-primary">' . esc_html__('Save Schedule', 'virakcloud-backup') . '</button></p>';
        echo '</form></div>';
    }

    public function render_backups(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('Backups', 'virakcloud-backup') . '</h1>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('vcbk_run_backup');
        echo '<input type="hidden" name="action" value="vcbk_run_backup" />';
        echo '<select name="type">';
        foreach (['full', 'db', 'files', 'incremental'] as $t) {
            printf('<option value="%s">%s</option>', esc_attr($t), esc_html($t));
        }
        echo '</select> ';
        echo '<button class="button button-primary">' . esc_html__('Run Backup', 'virakcloud-backup') . '</button>';
        echo '</form>';
        echo '</div>';
    }

    public function render_restore(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('Restore', 'virakcloud-backup') . '</h1>';
        echo '<p>' . esc_html__('Select a restore point from your VirakCloud S3 bucket and start restore. Current site will be snapshotted for rollback.', 'virakcloud-backup') . '</p>';
        echo '<p><em>' . esc_html__('Restore UI coming in subsequent iterations.', 'virakcloud-backup') . '</em></p>';
        echo '</div>';
    }

    public function render_migrate(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('Migrate', 'virakcloud-backup') . '</h1>';
        echo '<p>' . esc_html__('Move this site to another domain. We will update URLs even inside serialized data.', 'virakcloud-backup') . '</p>';
        echo '<p><em>' . esc_html__('Migration UI coming in subsequent iterations.', 'virakcloud-backup') . '</em></p>';
        echo '</div>';
    }

    public function render_logs(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('Logs', 'virakcloud-backup') . '</h1>';
        $lines = $this->logger->tail(200);
        echo '<pre style="max-height:500px;overflow:auto;background:#111;color:#eee;padding:12px;">' . esc_html(implode("\n", $lines)) . '</pre>';
        echo '</div>';
    }

    public function render_status(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('Status', 'virakcloud-backup') . '</h1>';
        echo '<ul>';
        echo '<li>' . esc_html__('PHP version: ', 'virakcloud-backup') . esc_html(PHP_VERSION) . '</li>';
        echo '<li>' . esc_html__('Memory limit: ', 'virakcloud-backup') . esc_html(ini_get('memory_limit')) . '</li>';
        echo '<li>' . esc_html__('Max execution time: ', 'virakcloud-backup') . esc_html((string) ini_get('max_execution_time')) . '</li>';
        echo '</ul>';
        echo '</div>';
    }
}

