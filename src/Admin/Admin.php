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
        add_action('admin_post_vcbk_wizard_next', [$this, 'handleWizardNext']);
        add_action('wp_ajax_vcbk_tail_logs', [$this, 'ajaxTailLogs']);
        add_action('wp_ajax_vcbk_progress', [$this, 'ajaxProgress']);
        add_action('admin_post_vcbk_wizard_next', [$this, 'handleWizardNext']);
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
        add_submenu_page(
            'vcbk',
            __('Setup Wizard', 'virakcloud-backup'),
            __('Setup Wizard', 'virakcloud-backup'),
            'manage_options',
            'vcbk-setup',
            [$this, 'renderWizard']
        );
        add_submenu_page(
            'vcbk',
            __('Setup Wizard', 'virakcloud-backup'),
            __('Setup Wizard', 'virakcloud-backup'),
            'manage_options',
            'vcbk-setup',
            [$this, 'renderWizard']
        );
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
            __('Migrate', 'virakcloud-backup'),
            __('Migrate', 'virakcloud-backup'),
            'update_core',
            'vcbk-migrate',
            [$this, 'renderMigrate']
        );
        add_submenu_page(
            'vcbk',
            __('Logs', 'virakcloud-backup'),
            __('Logs', 'virakcloud-backup'),
            'manage_options',
            'vcbk-logs',
            [$this, 'renderLogs']
        );
        add_submenu_page(
            'vcbk',
            __('Status', 'virakcloud-backup'),
            __('Status', 'virakcloud-backup'),
            'manage_options',
            'vcbk-status',
            [$this, 'renderStatus']
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
            $msg = __('S3 connection success!', 'virakcloud-backup');
            wp_safe_redirect(add_query_arg('message', rawurlencode($msg), admin_url('admin.php?page=vcbk-settings')));
        } catch (\Throwable $e) {
            $msg = __('S3 connection failed: ', 'virakcloud-backup') . $e->getMessage();
            wp_safe_redirect(add_query_arg('error', rawurlencode($msg), admin_url('admin.php?page=vcbk-settings')));
        }
        exit;
    }

    public function renderDashboard(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('VirakCloud Backup', 'virakcloud-backup') . '</h1>';
        echo '<p>'
            . esc_html__('Last backup:', 'virakcloud-backup')
            . ' '
            . esc_html(get_option('vcbk_last_backup', '—'))
            . '</p>';
        $runUrl = wp_nonce_url(
            admin_url('admin-post.php?action=vcbk_run_backup'),
            'vcbk_run_backup'
        );
        echo '<p><a class="button button-primary" href="' . esc_url($runUrl) . '">'
            . esc_html__('Run Backup Now', 'virakcloud-backup')
            . '</a></p>';
        echo '</div>';
    }

    public function renderSettings(): void
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
        echo '<p><button class="button">' . esc_html__('Save Settings', 'virakcloud-backup') . '</button> ';
        $testUrl = wp_nonce_url(
            admin_url('admin-post.php?action=vcbk_test_s3'),
            'vcbk_test_s3'
        );
        echo '<a class="button" href="' . esc_url($testUrl) . '">'
            . esc_html__('Test S3 Connection', 'virakcloud-backup')
            . '</a></p>';
        echo '</form></div>';
    }

    public function renderSchedules(): void
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
            $sel = selected($schedule['interval'], $i, false);
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($i),
                $sel,
                esc_html($i)
            );
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
        echo '</table><p><button class="button button-primary">'
            . esc_html__('Save Schedule', 'virakcloud-backup')
            . '</button></p>';
        echo '</form></div>';
    }

    public function renderBackups(): void
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
        echo '<label style="margin-left:10px"><input type="checkbox" name="upload_to_s3" /> ' . esc_html__('Upload to S3 after backup', 'virakcloud-backup') . '</label> ';
        echo '<button class="button button-primary">' . esc_html__('Run Backup', 'virakcloud-backup') . '</button>';
        echo '</form>';
        // Progress + live logs
        echo '<h2>' . esc_html__('Progress', 'virakcloud-backup') . '</h2>';
        echo '<div id="vcbk-progress" style="background:#eee;width:480px;height:16px;border-radius:8px;overflow:hidden;">
                <div id="vcbk-progress-bar" style="height:100%;width:0;background:#2271b1;"></div>
              </div>
              <p id="vcbk-progress-stage" style="margin-top:6px;color:#555;"></p>';
        echo '<h2>' . esc_html__('Live Logs', 'virakcloud-backup') . '</h2>';
        echo '<p><button class="button" id="vcbk-toggle-autorefresh">' . esc_html__('Start Auto-Refresh', 'virakcloud-backup') . '</button></p>';
        echo '<pre id="vcbk-log" style="max-height:380px;overflow:auto;background:#111;color:#eee;padding:12px;"></pre>';
        echo '<script>
        (function(){
            var running=false, timer=null;
            function fetchLogs(){
                fetch(ajaxurl+"?action=vcbk_tail_logs").then(r=>r.json()).then(function(j){
                    if(Array.isArray(j.lines)){
                        document.getElementById("vcbk-log").textContent=j.lines.join("\n");
                    }
                }).catch(()=>{});
            }
            function fetchProgress(){
                fetch(ajaxurl+"?action=vcbk_progress").then(r=>r.json()).then(function(j){
                    var p = j.percent||0; var stage=j.stage||"";
                    document.getElementById("vcbk-progress-bar").style.width=p+"%";
                    document.getElementById("vcbk-progress-stage").textContent=stage+" ("+p+"%)";
                }).catch(()=>{});
            }
            function tick(){ fetchLogs(); fetchProgress(); }
            document.getElementById("vcbk-toggle-autorefresh").addEventListener("click", function(){
                running = !running;
                this.textContent = running? "Stop Auto-Refresh" : "Start Auto-Refresh";
                if(running){ tick(); timer=setInterval(tick, 2000);} else { clearInterval(timer);} 
            });
        })();
        </script>';
        echo '</div>';
    }

    public function renderRestore(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('Restore', 'virakcloud-backup') . '</h1>';
        // phpcs:disable Generic.Files.LineLength
        echo '<p>' . esc_html__(
            'Select a restore point from your VirakCloud S3 bucket and start restore. Current site will be snapshotted for rollback.',
            'virakcloud-backup'
        ) . '</p>';
        // phpcs:enable Generic.Files.LineLength
        echo '<p><em>' . esc_html__('Restore UI coming in subsequent iterations.', 'virakcloud-backup') . '</em></p>';
        echo '</div>';
    }

    public function renderMigrate(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('Migrate', 'virakcloud-backup') . '</h1>';
        echo '<p>'
            . esc_html__(
                'Move this site to another domain. We will update URLs even inside serialized data.',
                'virakcloud-backup'
            )
            . '</p>';
        echo '<p><em>' . esc_html__('Migration UI coming in subsequent iterations.', 'virakcloud-backup') . '</em></p>';
        echo '</div>';
    }

    public function handleWizardNext(): void
    {
        check_admin_referer('vcbk_wizard');
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions', 'virakcloud-backup'));
        }
        $step = isset($_POST['step']) ? (int) $_POST['step'] : 1;
        if ($step === 1) {
            $data = wp_unslash($_POST);
            $config = $this->settings->sanitizeFromPost($data);
            $this->settings->set($config);
            $next = add_query_arg('step', '2', admin_url('admin.php?page=vcbk-setup'));
            wp_safe_redirect($next);
            exit;
        }
        if ($step === 2) {
            try {
                (new S3ClientFactory($this->settings, $this->logger))->create()
                    ->headBucket(['Bucket' => $this->settings->get()['s3']['bucket']]);
                $next = add_query_arg('step', '3', admin_url('admin.php?page=vcbk-setup'));
            } catch (\Throwable $e) {
                $next = add_query_arg([
                    'step' => '2',
                    'error' => rawurlencode($e->getMessage()),
                ], admin_url('admin.php?page=vcbk-setup'));
            }
            wp_safe_redirect($next);
            exit;
        }
        if ($step === 3) {
            $data = wp_unslash($_POST);
            $config = $this->settings->sanitizeFromPost($data);
            $this->settings->set($config);
            $done = add_query_arg('done', '1', admin_url('admin.php?page=vcbk-setup'));
            wp_safe_redirect($done);
            exit;
        }
        wp_safe_redirect(admin_url('admin.php?page=vcbk-setup'));
        exit;
    }

    public function renderWizard(): void
    {
        $step = isset($_GET['step']) ? (int) $_GET['step'] : 1;
        $cfg = $this->settings->get();
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('VirakCloud Backup • Setup Wizard', 'virakcloud-backup') . '</h1>';
        echo '<ol class="vcbk-steps"><li>' . esc_html__('Connect Storage', 'virakcloud-backup') . '</li><li>' . esc_html__('Test Connection', 'virakcloud-backup') . '</li><li>' . esc_html__('Schedule', 'virakcloud-backup') . '</li></ol>';
        if ($step === 1) {
            echo '<h2>' . esc_html__('Step 1: S3 Settings', 'virakcloud-backup') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('vcbk_wizard');
            echo '<input type="hidden" name="action" value="vcbk_wizard_next" />';
            echo '<input type="hidden" name="step" value="1" />';
            $s3 = $cfg['s3'];
            $fields = [
                'endpoint' => 'Endpoint',
                'region' => 'Region (optional)',
                'bucket' => 'Bucket',
                'access_key' => 'Access Key',
                'secret_key' => 'Secret Key',
            ];
            echo '<table class="form-table">';
            foreach ($fields as $key => $label) {
                $val = isset($s3[$key]) ? (string) $s3[$key] : '';
                $type = ($key === 'secret_key') ? 'password' : 'text';
                echo '<tr><th><label>' . esc_html__($label, 'virakcloud-backup') . '</label></th><td>';
                printf(
                    '<input type="%s" name="s3[%s]" value="%s" class="regular-text" />',
                    esc_attr($type),
                    esc_attr($key),
                    esc_attr($val)
                );
                echo '</td></tr>';
            }
            echo '<tr><th>' . esc_html__('Path Style', 'virakcloud-backup') . '</th><td>';
            printf(
                '<label><input type="checkbox" name="s3[path_style]" %s /> %s</label>',
                checked(!empty($s3['path_style']), true, false),
                esc_html__('Use path-style addressing (recommended)', 'virakcloud-backup')
            );
            echo '</td></tr>';
            echo '</table><p><button class="button button-primary">' . esc_html__('Continue', 'virakcloud-backup') . '</button></p>';
            echo '</form>';
        } elseif ($step === 2) {
            echo '<h2>' . esc_html__('Step 2: Test Connection', 'virakcloud-backup') . '</h2>';
            if (!empty($_GET['error'])) {
                echo '<div class="notice notice-error"><p>' . esc_html((string) $_GET['error']) . '</p></div>';
            }
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('vcbk_wizard');
            echo '<input type="hidden" name="action" value="vcbk_wizard_next" />';
            echo '<input type="hidden" name="step" value="2" />';
            echo '<p>' . esc_html__('We will try to access your bucket using the settings from Step 1.', 'virakcloud-backup') . '</p>';
            echo '<p><button class="button button-primary">' . esc_html__('Run Connection Test', 'virakcloud-backup') . '</button></p>';
            echo '</form>';
        } else {
            echo '<h2>' . esc_html__('Step 3: Schedule Backups', 'virakcloud-backup') . '</h2>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('vcbk_wizard');
            echo '<input type="hidden" name="action" value="vcbk_wizard_next" />';
            echo '<input type="hidden" name="step" value="3" />';
            $schedule = $cfg['schedule'];
            $intervals = ['2h','4h','8h','12h','daily','weekly','fortnightly','monthly'];
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
            echo '</table><p><button class="button button-primary">' . esc_html__('Finish Setup', 'virakcloud-backup') . '</button></p>';
            echo '</form>';
            if (!empty($_GET['done'])) {
                echo '<div class="notice notice-success"><p>' . esc_html__('Setup is complete. You can run your first backup from the Backups tab.', 'virakcloud-backup') . '</p></div>';
            }
        }
        echo '</div>';
    }

    public function renderLogs(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('Logs', 'virakcloud-backup') . '</h1>';
        $lines = $this->logger->tail(500);
        echo '<pre style="max-height:500px;overflow:auto;background:#111;color:#eee;padding:12px;">'
            . esc_html(implode("\n", $lines))
            . '</pre>';
        echo '</div>';
    }

    public function ajaxTailLogs(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden']);
        }
        wp_send_json(['lines' => $this->logger->tail(400)]);
    }

    public function ajaxProgress(): void
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'forbidden']);
        }
        wp_send_json($this->logger->getProgress());
    }

    public function renderStatus(): void
    {
        echo '<div class="wrap"><h1>' . esc_html__('Status', 'virakcloud-backup') . '</h1>';
        echo '<ul>';
        echo '<li>' . esc_html__('PHP version: ', 'virakcloud-backup') . esc_html(PHP_VERSION) . '</li>';
        echo '<li>' . esc_html__('Memory limit: ', 'virakcloud-backup') . esc_html(ini_get('memory_limit')) . '</li>';
        echo '<li>'
            . esc_html__('Max execution time: ', 'virakcloud-backup')
            . esc_html((string) ini_get('max_execution_time'))
            . '</li>';
        echo '</ul>';
        echo '</div>';
    }
}
