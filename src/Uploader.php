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
            $ct = self::guessContentType($filePath);
            $md5b64 = '';
            try {
                $raw = @md5_file($filePath, true);
                if ($raw !== false) {
                    $md5b64 = base64_encode($raw);
                }
            } catch (\Throwable $e) {
                // ignore
            }
            $this->client->putObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => fopen($filePath, 'rb'),
                'ContentType' => $ct,
                'ContentEncoding' => 'identity',
                // MD5 guards against rare in-flight corruption for small objects
                // (S3 will validate and reject if mismatch)
                'ContentMD5' => $md5b64 !== '' ? $md5b64 : null,
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
            'ContentType' => self::guessContentType($filePath),
            'ContentEncoding' => 'identity',
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
            // Wrap the file handle and limit to this part window starting at the absolute file offset
            $body = new LimitStream(Utils::streamFor($fp), $length, $offset);
            $result = $this->client->uploadPart([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'Body' => $body,
                'ContentLength' => $length,
            ]);
            // Close part handle after transmission
            try {
                $body->close();
            } catch (\Throwable $e) {
                // ignore
            }
            if (is_resource($fp)) {
                @fclose($fp);
            }
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

    private static function guessContentType(string $filePath): string
    {
        $fn = strtolower($filePath);
        if (str_ends_with($fn, '.zip')) {
            return 'application/zip';
        }
        if (str_ends_with($fn, '.tar.gz') || str_ends_with($fn, '.tgz')) {
            return 'application/gzip';
        }
        if (str_ends_with($fn, '.json')) {
            return 'application/json';
        }
        return 'application/octet-stream';
    }
}
