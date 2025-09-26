<?php

namespace VirakCloud\Backup;

class Settings
{
    private string $option = 'vcbk_settings';

    /**
     * @return array<string, mixed>
     */
    public function get(): array
    {
        $defaults = [
            's3' => [
                'endpoint' => defined('VCBK_S3_ENDPOINT')
                    ? (string) constant('VCBK_S3_ENDPOINT')
                    : 'https://s3.virakcloud.com',
                'region' => getenv('VCBK_S3_REGION') ?: '',
                'bucket' => getenv('VCBK_S3_BUCKET') ?: '',
                'access_key' => getenv('VCBK_S3_KEY') ?: '',
                'secret_key' => getenv('VCBK_S3_SECRET') ?: '',
                'path_style' => (bool) (getenv('VCBK_PATH_STYLE') ?: true),
                'sse' => false,
            ],
            'backup' => [
                'type' => 'full',
                'include' => ['wp-content'],
                // Exclude common heavy directories; be precise to avoid false positives
                'exclude' => [
                    '*/cache/*',
                    '*/node_modules/*',
                    'wp-content/uploads/virakcloud-backup',
                ],
                // Force zip for performance and compatibility
                'archive_format' => 'zip',
                'encryption' => [
                    'enabled' => false,
                    'cipher' => 'AES-256-GCM',
                ],
                'retention' => [
                    'keep_last' => 10,
                    'days' => 30,
                    'keep_weekly' => 8,
                    'keep_monthly' => 12,
                ],
            ],
            'schedule' => [
                'interval' => 'weekly',
                'start_time' => '01:30',
                'timezone' => 'site',
                'catchup' => true,
            ],
            'notifications' => [
                'email' => [get_bloginfo('admin_email') ?: ''],
                'webhook' => null,
            ],
        ];
        $saved = get_option($this->option, []);
        $merged = wp_parse_args($saved, $defaults);
        // Always enforce zip regardless of older saved values
        $merged['backup']['archive_format'] = 'zip';
        // Normalize legacy exclude patterns (migration from broad substrings)
        if (isset($merged['backup']['exclude']) && is_array($merged['backup']['exclude'])) {
            $norm = [];
            foreach ($merged['backup']['exclude'] as $pat) {
                if ($pat === 'cache') {
                    $pat = '*/cache/*';
                } elseif ($pat === 'node_modules') {
                    $pat = '*/node_modules/*';
                } elseif ($pat === 'uploads/virakcloud-backup' || $pat === 'virakcloud-backup') {
                    $pat = 'wp-content/uploads/virakcloud-backup';
                }
                if ($pat !== '' && !in_array($pat, $norm, true)) {
                    $norm[] = $pat;
                }
            }
            $merged['backup']['exclude'] = $norm;
        }
        return $merged;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function set(array $config): void
    {
        update_option($this->option, $config, false);
    }

    /**
     * Compute a stable site-specific S3 prefix component.
     * Uses host and path from home_url plus a short hash for uniqueness.
     */
    public function sitePrefix(): string
    {
        $url = home_url('/');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $slug = trim($host . str_replace('/', '-', rtrim($path, '/')), '-');
        $slug = preg_replace('/[^a-zA-Z0-9\-_.]/', '-', $slug) ?: 'site';
        $hash = substr(hash('sha1', $url), 0, 8);
        return $slug . '-' . $hash;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function sanitizeFromPost(array $data): array
    {
        $cfg = $this->get();
        if (isset($data['s3']) && is_array($data['s3'])) {
            $cfg['s3']['endpoint'] = esc_url_raw($data['s3']['endpoint'] ?? $cfg['s3']['endpoint']);
            $cfg['s3']['region'] = sanitize_text_field((string) ($data['s3']['region'] ?? ''));
            $cfg['s3']['bucket'] = sanitize_text_field((string) ($data['s3']['bucket'] ?? ''));
            $cfg['s3']['access_key'] = sanitize_text_field((string) ($data['s3']['access_key'] ?? ''));
            // Never log secret; store as-is (optionally encrypted in future)
            $secret = (string) ($data['s3']['secret_key'] ?? '');
            if ($secret !== '') {
                $cfg['s3']['secret_key'] = $secret;
            }
            $cfg['s3']['path_style'] = !empty($data['s3']['path_style']);
        }
        if (isset($data['schedule']) && is_array($data['schedule'])) {
            $cfg['schedule']['interval'] = in_array(
                $data['schedule']['interval'],
                ['2h', '4h', '8h', '12h', 'daily', 'weekly', 'fortnightly', 'monthly'],
                true
            ) ? $data['schedule']['interval'] : 'weekly';
            $cfg['schedule']['start_time'] = preg_match('/^\d{2}:\d{2}$/', (string) $data['schedule']['start_time'])
                ? (string) $data['schedule']['start_time']
                : '01:30';
            $cfg['schedule']['catchup'] = !empty($data['schedule']['catchup']);
        }
        if (isset($data['backup']) && is_array($data['backup'])) {
            $allowed = ['full', 'db', 'files', 'incremental'];
            $type = (string) ($data['backup']['type'] ?? $cfg['backup']['type']);
            $cfg['backup']['type'] = in_array($type, $allowed, true) ? $type : 'full';
            // Always zip; ignore any posted archive_format
            $cfg['backup']['archive_format'] = 'zip';
        }
        return $cfg;
    }
}
