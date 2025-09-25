<?php
// Capability assumed checked by caller.
$tz = wp_timezone();
$now = new DateTimeImmutable('now', $tz);
$last = get_option('vcbk_last_backup');
$lastText = $last ? human_time_diff(strtotime((string) $last), time()) . ' ' . __('ago', 'virakcloud-backup') : __('never', 'virakcloud-backup');
$nextTs = wp_next_scheduled('vcbk_cron_run');
$nextText = $nextTs ? wp_date('Y-m-d H:i', $nextTs, $tz) : __('not scheduled', 'virakcloud-backup');
$mem = ini_get('memory_limit');
$maxExec = ini_get('max_execution_time');
$free = function_exists('disk_free_space') ? @disk_free_space(WP_CONTENT_DIR) : null;
$freeText = $free !== false && $free !== null ? size_format((float) $free) : __('unknown', 'virakcloud-backup');

$s3Ok = false; $s3Err = '';
try {
    (new \VirakCloud\Backup\S3ClientFactory(new \VirakCloud\Backup\Settings(), new \VirakCloud\Backup\Logger()))
        ->create()->headBucket(['Bucket' => (new \VirakCloud\Backup\Settings())->get()['s3']['bucket']]);
    $s3Ok = true;
} catch (\Throwable $e) {
    $s3Err = $e->getMessage();
}

function vcbk_row($label, $value, $ok)
{
    $class = $ok ? 'vcbk-ok' : 'vcbk-warn';
    echo '<tr class="' . esc_attr($class) . '"><th scope="row">' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
}
?>
<div class="wrap">
  <h1><?php echo esc_html__('Health', 'virakcloud-backup'); ?></h1>
  <table class="widefat striped">
    <tbody>
      <?php vcbk_row(__('Last backup', 'virakcloud-backup'), $lastText, !empty($last)); ?>
      <?php vcbk_row(__('Next run', 'virakcloud-backup'), $nextText, (bool) $nextTs); ?>
      <?php vcbk_row(__('PHP memory_limit', 'virakcloud-backup'), (string) $mem, (int) wp_convert_hr_to_bytes((string) $mem) >= 256*1024*1024); ?>
      <?php vcbk_row(__('Max execution time', 'virakcloud-backup'), (string) $maxExec, (int) $maxExec >= 120); ?>
      <?php vcbk_row(__('Free disk space', 'virakcloud-backup'), (string) $freeText, $free !== null && $free !== false && $free > 500*1024*1024); ?>
      <tr class="<?php echo esc_attr($s3Ok ? 'vcbk-ok' : 'vcbk-err'); ?>">
        <th scope="row"><?php echo esc_html__('VirakCloud S3 connectivity', 'virakcloud-backup'); ?></th>
        <td><?php echo $s3Ok ? esc_html__('OK', 'virakcloud-backup') : esc_html($s3Err); ?></td>
      </tr>
    </tbody>
  </table>
  <p style="margin-top:8px;color:#555;">
    <?php echo esc_html__('Tip: For reliability, configure a real cron to call wp-cron.php every 5 minutes.', 'virakcloud-backup'); ?>
  </p>
</div>

