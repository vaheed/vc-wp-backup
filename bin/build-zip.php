<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$dist = $root . '/dist';
if (!is_dir($dist)) {
    mkdir($dist, 0775, true);
}
$zipPath = $dist . '/virakcloud-backup.zip';
if (file_exists($zipPath)) {
    unlink($zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Cannot create zip\n");
    exit(1);
}

function addDir(ZipArchive $zip, string $dir, string $base): void {
    $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iter as $file) {
        $path = (string) $file;
        if (preg_match('#/(vendor|dist|node_modules)/#', $path)) continue;
        $rel = ltrim(str_replace($base, '', $path), '/');
        if (is_dir($path)) {
            $zip->addEmptyDir($rel);
        } else {
            $zip->addFile($path, $rel);
        }
    }
}

addDir($zip, $root, $root . '/');
$zip->close();
echo "Built: $zipPath\n";

