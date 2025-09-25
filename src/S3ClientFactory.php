<?php

namespace VirakCloud\Backup;

use Aws\S3\S3Client;

class S3ClientFactory
{
    private Settings $settings;
    private ?Logger $logger;

    public function __construct(Settings $settings, ?Logger $logger = null)
    {
        $this->settings = $settings;
        $this->logger = $logger;
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
        $region = (string) ($s3['region'] ?? '');
        if ($region === '') {
            // Fallback region for S3-compatible endpoints; AWS SDK requires a value.
            $region = 'us-east-1';
        }
        $args = [
            'version' => 'latest',
            'region' => $region,
            'endpoint' => (string) $s3['endpoint'],
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
        if ($this->logger) {
            $this->logger->debug('s3_client_create', [
                'endpoint' => $args['endpoint'],
                'region' => $args['region'],
                'path_style' => $args['use_path_style_endpoint'],
                'key_set' => $args['credentials']['key'] !== '',
            ]);
        }
        return new S3Client($args);
    }
}
