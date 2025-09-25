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

    /**
     * @param int $partSize Chunk size in bytes (min 1)
     * @return array{parts:int}
     */
    /**
     * Upload file choosing the optimal strategy.
     * For small files (<= 5 MiB) use single putObject. Otherwise use multipart upload.
     * @return array{parts:int}
     */
    public function uploadAuto(string $key, string $filePath, int $partSize = 134217728): array
    {
        $size = filesize($filePath) ?: 0;
        if ($size <= 5 * 1024 * 1024) {
            $this->logger->debug('upload_putobject', ['key' => $key, 'size' => $size]);
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => fopen($filePath, 'rb'),
            ]);
            return ['parts' => 1];
        }
        return $this->uploadMultipart($key, $filePath, $partSize);
    }

    public function uploadMultipart(string $key, string $filePath, int $partSize = 134217728): array
    {
        if ($partSize < 1) {
            $partSize = 1;
        }
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
            $length = (int) min($partSize, $size - $offset);
            if ($length <= 0) {
                break;
            }
            /** @var positive-int $length */
            $length = $length;
            $body = fread($fh, $length);
            if ($body === false) {
                throw new \RuntimeException('Read error during multipart upload');
            }
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
            $processed = $offset + $length;
            $this->logger->info('upload_part', ['part' => $partNumber, 'bytes' => $processed]);
            $this->logger->setProgress(
                (int) floor(80 + (15 * $processed / max(1, $size))),
                'uploading',
                ['bytesProcessed' => $processed, 'totalBytes' => $size]
            );
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
