<?php

namespace VirakCloud\Backup;

class ArchiveBuilder
{
    private Logger $logger;
    private int $progressCurrent = 40;
    private int $progressMax = 69;
    private int $processed = 0;
    private int $totalBytes = 0;
    private int $processedBytes = 0;
    private int $totalFiles = 0;
    private int $processedFiles = 0;
    private int $lastReportedPercent = -1;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Build an archive of the given paths.
     *
     * @param string   $format  zip|tar.gz
     * @param string[] $paths   Absolute paths to include
     * @param string   $output  Absolute path of the archive file to create
     * @param string[] $exclude Relative path patterns to exclude
     * @param array{modifiedSince?:int}|array{} $options Optional tuning (e.g., modifiedSince for incremental)
     * @return array<string, mixed>
     */
    public function build(string $format, array $paths, string $output, array $exclude = [], array $options = []): array
    {
        $this->logger->debug('archive_build_start', ['format' => $format, 'output' => $output]);
        $format = $format === 'tar.gz' ? 'tar.gz' : 'zip';
        $this->progressCurrent = 40;
        $this->progressMax = 69; // keep 70 for next stage
        $this->processed = 0;
        $this->processedBytes = 0;
        $this->processedFiles = 0;
        $this->lastReportedPercent = -1;
        $manifest = [
            'format' => $format,
            'files' => [],
            'sha256' => null,
        ];
        $modifiedSince = isset($options['modifiedSince']) ? (int) $options['modifiedSince'] : null;

        // Pre-scan to compute realistic totals for progress
        [$this->totalBytes, $this->totalFiles] = $this->scanTotals($paths, $exclude, $modifiedSince);
        $this->logger->debug('archive_scan', ['totalBytes' => $this->totalBytes, 'totalFiles' => $this->totalFiles]);

        if ($format === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($output, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create ZIP: ' . $output);
            }
            foreach ($paths as $base) {
                $base = rtrim($base, '/');
                if (is_dir($base)) {
                    $this->addDirToZip($zip, $base, $exclude, $modifiedSince);
                } elseif (is_file($base)) {
                    $this->addFileToZip($zip, $base, $exclude);
                }
            }
            $zip->close();
        } else {
            // tar.gz via PharData
            $tar = substr($output, 0, -3); // remove .gz
            $phar = new \PharData($tar);
            foreach ($paths as $base) {
                if (is_dir($base)) {
                    $this->addDirToTar($phar, $base, $exclude, $modifiedSince);
                } elseif (is_file($base)) {
                    $this->addFileToTar($phar, $base, $exclude);
                }
            }
            $phar->compress(\Phar::GZ);
            unset($phar);
            @unlink($tar);
        }

        // Cap progress at 70 after archiving
        if ($this->progressCurrent < 70) {
            $this->progressCurrent = 70;
            // Use plain strings to avoid WP i18n dependency in tests
            $this->logger->setProgress(70, 'Upload Pending');
        }

        // Hash (may be expensive on large files; extend time limit but avoid WP-only calls here)
        $hash = null;
        try {
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            $hash = hash_file('sha256', $output);
        } catch (\Throwable $e) {
            $this->logger->error('archive_hash_failed', ['message' => $e->getMessage()]);
        }
        $manifest['sha256'] = $hash;
        $this->logger->debug('archive_build_complete', ['output' => $output, 'sha256' => $hash]);
        return $manifest;
    }

    /**
     * @param string[] $exclude
     */
    private function addDirToZip(\ZipArchive $zip, string $base, array $exclude, ?int $modifiedSince = null): void
    {
        $baseName = basename($base);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = (string) $file;
            if ($modifiedSince !== null && is_file($path)) {
                $mt = @filemtime($path);
                if ($mt !== false && $mt < $modifiedSince) {
                    continue;
                }
            }
            $rel = $baseName . '/' . ltrim(substr($path, strlen($base)), '/');
            if ($this->isExcluded($rel, $exclude)) {
                continue;
            }
            if (is_dir($path)) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($path, $rel);
                $this->tickArchivingProgress(@filesize($path) ?: 0);
            }
        }
    }

    /**
     * @param string[] $exclude
     */
    private function addDirToTar(\PharData $phar, string $base, array $exclude, ?int $modifiedSince = null): void
    {
        $baseName = basename($base);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = (string) $file;
            if ($modifiedSince !== null && is_file($path)) {
                $mt = @filemtime($path);
                if ($mt !== false && $mt < $modifiedSince) {
                    continue;
                }
            }
            $rel = $baseName . '/' . ltrim(substr($path, strlen($base)), '/');
            if ($this->isExcluded($rel, $exclude)) {
                continue;
            }
            $phar->addFile($path, $rel);
            $this->tickArchivingProgress(@filesize($path) ?: 0);
        }
    }

    /**
     * @param string[] $exclude
     */
    private function addFileToZip(\ZipArchive $zip, string $filePath, array $exclude): void
    {
        $rel = basename($filePath);
        if ($this->isExcluded($rel, $exclude)) {
            return;
        }
        $zip->addFile($filePath, $rel);
        $this->tickArchivingProgress(@filesize($filePath) ?: 0);
    }

    /**
     * @param string[] $exclude
     */
    private function addFileToTar(\PharData $phar, string $filePath, array $exclude): void
    {
        $rel = basename($filePath);
        if ($this->isExcluded($rel, $exclude)) {
            return;
        }
        $phar->addFile($filePath, $rel);
        $this->tickArchivingProgress(@filesize($filePath) ?: 0);
    }

    private function tickArchivingProgress(int $bytesAdded): void
    {
        $this->processed++;
        $this->processedFiles++;
        $this->processedBytes += max(0, $bytesAdded);
        if ($this->totalBytes > 0) {
            $pct = 40 + (int) floor(30 * $this->processedBytes / max(1, $this->totalBytes));
            $pct = max(40, min(69, $pct));
            if ($pct !== $this->lastReportedPercent) {
                $this->lastReportedPercent = $pct;
                $this->progressCurrent = $pct;
                $this->logger->setProgress($pct, 'Archiving Files', [
                    'processedBytes' => $this->processedBytes,
                    'totalBytes' => $this->totalBytes,
                    'processedFiles' => $this->processedFiles,
                    'totalFiles' => $this->totalFiles,
                ]);
            }
        }
    }

    /**
     * @param string[] $exclude
     */
    private function isExcluded(string $relativePath, array $exclude): bool
    {
        // Rely solely on configured patterns; no hard-coded exclusions here.
        foreach ($exclude as $pattern) {
            if ($pattern === '') {
                continue;
            }
            $pattern = (string) $pattern;
            // If pattern looks like a glob or includes a slash, use fnmatch
            if (strpbrk($pattern, "*/?[") !== false || strpos($pattern, '/') !== false || strpos($pattern, '\\') !== false) {
                if (fnmatch($pattern, $relativePath)) {
                    return true;
                }
            } else {
                // Treat as a path-segment match (avoid broad substring matches)
                $segments = preg_split('#[\\\\/]#', $relativePath) ?: [];
                if (in_array($pattern, $segments, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Compute total bytes and files to be archived (after exclusions and optional modifiedSince).
     * @param string[] $paths
     * @param string[] $exclude
     * @return array{0:int,1:int} [bytes, files]
     */
    private function scanTotals(array $paths, array $exclude, ?int $modifiedSince): array
    {
        $bytes = 0;
        $files = 0;
        foreach ($paths as $base) {
            $base = rtrim($base, '/');
            if (is_dir($base)) {
                $baseName = basename($base);
                $it = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
                );
                foreach ($it as $file) {
                    $path = (string) $file;
                    if ($modifiedSince !== null && is_file($path)) {
                        $mt = @filemtime($path);
                        if ($mt !== false && $mt < $modifiedSince) {
                            continue;
                        }
                    }
                    $rel = $baseName . '/' . ltrim(substr($path, strlen($base)), '/');
                    if ($this->isExcluded($rel, $exclude)) {
                        continue;
                    }
                    if (is_file($path)) {
                        $files++;
                        $bytes += @filesize($path) ?: 0;
                    }
                }
            } elseif (is_file($base)) {
                $rel = basename($base);
                if (!$this->isExcluded($rel, $exclude)) {
                    $files++;
                    $bytes += @filesize($base) ?: 0;
                }
            }
        }
        return [$bytes, $files];
    }
}
