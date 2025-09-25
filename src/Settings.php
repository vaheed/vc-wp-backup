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
                'exclude' => ['cache', 'node_modules'],
                'archive_format' => getenv('VCBK_ARCHIVE_FORMAT') ?: 'zip',
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
        return $cfg;
    }
}
