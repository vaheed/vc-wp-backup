<?php

namespace VirakCloud\Backup;

use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\Psr7\LimitStream;

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
    public function uploadAuto(string $key, string $filePath, int $partSize = 8388608): array
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

    /**
     * Perform multipart upload of a file to S3.
     * @return array{parts:int}
     */
    public function uploadMultipart(string $key, string $filePath, int $partSize = 8388608): array
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

        $partNumber = 1;
        $offset = 0;
        while ($offset < $size) {
            $length = (int) min($partSize, $size - $offset);
            if ($length <= 0) {
                break;
            }
            /** @var positive-int $length */
            $length = $length;
            // Open a fresh handle per part to avoid pointer/ftell issues in SDK
            $fp = fopen($filePath, 'rb');
            if (!$fp) {
                throw new \RuntimeException('Cannot open file for upload');
            }
            if (fseek($fp, $offset) !== 0) {
                fclose($fp);
                throw new \RuntimeException('Seek failed during multipart upload');
            }
            // Limit read window to this part size
            $body = new LimitStream(Utils::streamFor($fp), $length, 0);
            $result = $this->client->uploadPart([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'Body' => $body,
                'ContentLength' => $length,
            ]);
            // Close part handle after transmission
            try { $body->close(); } catch (\Throwable $e) { /* ignore */ }
            fclose($fp);
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
        // Ensure no stray handles

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
