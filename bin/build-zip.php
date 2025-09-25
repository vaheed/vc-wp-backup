<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dist = $root . '/dist';
if (!is_dir($dist)) {
    mkdir($dist, 0775, true);
}
$slug = 'virakcloud-backup';
$zipPath = $dist . '/' . $slug . '.zip';
if (file_exists($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create zip\n");
    exit(1);
}

function addDir(ZipArchive $zip, string $dir, string $base, string $prefix): void {
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        $path = (string) $file;
        // Exclude build/development-only directories
        if (preg_match('#/(dist|node_modules|\.git|\.github|tests|bin|tools)/#', $path)) {
            continue;
        }
        $rel = $prefix . ltrim(str_replace($base, '', $path), '/');
        if (is_dir($path)) {
            $zip->addEmptyDir($rel);
        } else {
            $zip->addFile($path, $rel);
        }
    }
}
// Ensure everything is nested under the plugin slug folder inside the zip, e.g. virakcloud-backup/
addDir($zip, $root, $root . '/', $slug . '/');
$zip->close();
echo "Built: $zipPath\n";
