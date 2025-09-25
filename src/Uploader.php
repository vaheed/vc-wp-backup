<?php

namespace VirakCloud\Backup;

use Aws\S3\S3Client;

class Uploader
{
    private S3Client $client;
    private string $bucket;
    private Logger $logger;

    public function __construct(S3Client $client, string $bucket, Logger $logger)
    {
        $this->client = $client;
        $this->bucket = $bucket;
        $this->logger = $logger;
    }

    public function uploadMultipart(string $key, string $filePath, int $partSize = 134217728): array
    {
        $size = filesize($filePath);
        $this->logger->info('upload_start', ['key' => $key, 'size' => $size]);
        $create = $this->client->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        $uploadId = $create['UploadId'];
        $parts = [];

        $fh = fopen($filePath, 'rb');
        if (!$fh) {
            throw new \RuntimeException('Cannot open file for upload');
        }
        $partNumber = 1;
        $offset = 0;
        while ($offset < $size) {
            $length = min($partSize, $size - $offset);
            $body = fread($fh, $length);
            $result = $this->client->uploadPart([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'Body' => $body,
            ]);
            $parts[] = [
                'PartNumber' => $partNumber,
                'ETag' => $result['ETag'],
            ];
            $this->logger->info('upload_part', ['part' => $partNumber]);
            $partNumber++;
            $offset += $length;
        }
        fclose($fh);

        $this->client->completeMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => $parts],
        ]);
        $this->logger->info('upload_complete', ['key' => $key]);
        return ['parts' => count($parts)];
    }
}
