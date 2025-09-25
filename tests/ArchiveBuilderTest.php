<?php

use PHPUnit\Framework\TestCase;
use VirakCloud\Backup\ArchiveBuilder;
use VirakCloud\Backup\Logger;

final class ArchiveBuilderTest extends TestCase
{
    private string $work;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/vcbk-arch-test-' . uniqid();
        mkdir($this->work, 0777, true);
        file_put_contents($this->work . '/file.txt', 'hello');
        mkdir($this->work . '/dir', 0777, true);
        file_put_contents($this->work . '/dir/inner.txt', 'world');
        if (!function_exists('wp_json_encode')) {
            function wp_json_encode($data, $options = 0) { return json_encode($data, $options); }
        }
    }

    protected function tearDown(): void
    {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
        @rmdir($this->work);
    }

    public function testBuildZip(): void
    {
        $logger = new Logger();
        $arch = new ArchiveBuilder($logger);
        $out = $this->work . '/out.zip';
        $manifest = $arch->build('zip', [$this->work], $out, []);
        $this->assertFileExists($out);
        $this->assertArrayHasKey('sha256', $manifest);
        $this->assertSame(hash_file('sha256', $out), $manifest['sha256']);
    }
}

