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
     * @param string $format zip|tar.gz
     * @param array $paths Absolute paths to include
     * @param string $output Absolute path of the archive file to create
     * @param array $exclude Relative path patterns to exclude
     */
    public function build(string $format, array $paths, string $output, array $exclude = []): array
    {
        $format = $format === 'tar.gz' ? 'tar.gz' : 'zip';
        $manifest = [
            'format' => $format,
            'files' => [],
            'sha256' => null,
        ];

        if ($format === 'zip') {
            $zip = new \ZipArchive();
            if ($zip->open($output, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Unable to create ZIP: ' . $output);
            }
            foreach ($paths as $base) {
                $base = rtrim($base, '/');
                $this->addDirToZip($zip, $base, $exclude);
            }
            $zip->close();
        } else {
            // tar.gz via PharData
            $tar = substr($output, 0, -3); // remove .gz
            $phar = new \PharData($tar);
            foreach ($paths as $base) {
                $this->addDirToTar($phar, $base, $exclude);
            }
            $phar->compress(\Phar::GZ);
            unset($phar);
            @unlink($tar);
        }

        // Hash
        $hash = hash_file('sha256', $output);
        $manifest['sha256'] = $hash;
        return $manifest;
    }

    private function addDirToZip(\ZipArchive $zip, string $base, array $exclude): void
    {
        $baseName = basename($base);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = (string) $file;
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

    private function addDirToTar(\PharData $phar, string $base, array $exclude): void
    {
        $baseName = basename($base);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            $path = (string) $file;
            $rel = $baseName . '/' . ltrim(substr($path, strlen($base)), '/');
            if ($this->isExcluded($rel, $exclude)) {
                continue;
            }
            $phar->addFile($path, $rel);
        }
    }

    private function isExcluded(string $relativePath, array $exclude): bool
    {
        foreach ($exclude as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (fnmatch($pattern, $relativePath)) {
                return true;
            }
        }
        return false;
    }
}
