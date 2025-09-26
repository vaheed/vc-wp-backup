<?php

namespace VirakCloud\Backup;

class ArchiveBuilder
{
    private Logger $logger;

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
        $manifest = [
            'format' => $format,
            'files' => [],
            'sha256' => null,
        ];
        $modifiedSince = isset($options['modifiedSince']) ? (int) $options['modifiedSince'] : null;

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

        // Hash
        $hash = hash_file('sha256', $output);
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
    }

    /**
     * @param string[] $exclude
     */
    private function isExcluded(string $relativePath, array $exclude): bool
    {
        // Always exclude our plugin working directory if present in path
        if (strpos($relativePath, 'virakcloud-backup/') !== false || strpos($relativePath, 'virakcloud-backup\\') !== false) {
            return true;
        }
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
                // Otherwise treat as a substring (directory or file name)
                if (strpos($relativePath, $pattern) !== false) {
                    return true;
                }
            }
        }
        return false;
    }
}
