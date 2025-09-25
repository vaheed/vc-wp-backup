<?php

namespace VirakCloud\Backup;

use Aws\S3\S3Client;

class S3ClientFactory
{
    private Settings $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function create(): S3Client
    {
        if (!class_exists(S3Client::class)) {
            throw new \RuntimeException(
                __(
                    'Composer dependencies missing (aws/aws-sdk-php). Please run composer install.',
                    'virakcloud-backup'
                )
            );
        }
        $s3 = $this->settings->get()['s3'];
        $args = [
            'version' => 'latest',
            'region' => $s3['region'],
            'endpoint' => $s3['endpoint'],
            'use_path_style_endpoint' => (bool) $s3['path_style'],
            'credentials' => [
                'key' => (string) $s3['access_key'],
                'secret' => (string) $s3['secret_key'],
            ],
            'http' => [
                'connect_timeout' => 10,
                'timeout' => 600,
            ],
        ];
        return new S3Client($args);
    }
}
