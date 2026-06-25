<?php declare(strict_types=1);

/**
 * Copyright 2024 Adobe
 * All Rights Reserved.
 */

namespace Magento\Framework\Archive\Test\Unit;

use Magento\Framework\Archive\Tar;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

class TarTest extends TestCase
{
    /**
     * @var string
     */
    private $workDir;

    protected function setUp(): void
    {
        $this->workDir = sys_get_temp_dir() . '/magento_tar_test_' . uniqid();
        mkdir($this->workDir, 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workDir);
    }

    public function testUnpackRejectsPathTraversalEntry(): void
    {
        $tarPath = $this->workDir . '/evil.tar';
        $destination = $this->workDir . '/extract/';
        mkdir($destination, 0777, true);
        file_put_contents($tarPath, $this->buildTar(['../evil_pwned.txt' => 'pwned']));

        $this->expectException(LocalizedException::class);
        try {
            (new Tar())->unpack($tarPath, $destination);
        } finally {
            $this->assertFileDoesNotExist(
                $this->workDir . '/evil_pwned.txt',
                'Traversal entry must not be written outside the extraction directory'
            );
        }
    }

    public function testUnpackExtractsRegularEntry(): void
    {
        $tarPath = $this->workDir . '/good.tar';
        $destination = $this->workDir . '/extract/';
        mkdir($destination, 0777, true);
        file_put_contents($tarPath, $this->buildTar(['sub/dir/ok.txt' => 'hello']));

        (new Tar())->unpack($tarPath, $destination);

        $extracted = $destination . 'sub/dir/ok.txt';
        $this->assertFileExists($extracted);
        $this->assertSame('hello', file_get_contents($extracted));
    }

    /**
     * Build an in-memory ustar archive for the given entries.
     *
     * @param array<string, string> $entries name => content
     * @return string
     */
    private function buildTar(array $entries): string
    {
        $tar = '';
        foreach ($entries as $name => $content) {
            $tar .= $this->buildHeader($name, strlen($content));
            $tar .= $content . str_repeat("\0", (512 - strlen($content) % 512) % 512);
        }

        return $tar . str_repeat("\0", 1024);
    }

    /**
     * Build a 512-byte ustar header with a valid checksum.
     *
     * @param string $name
     * @param int $size
     * @return string
     */
    private function buildHeader(string $name, int $size): string
    {
        $header = pack('a100', $name)
            . pack('a8', sprintf("%07o\0", 0644))
            . pack('a8', sprintf("%07o\0", 0))
            . pack('a8', sprintf("%07o\0", 0))
            . pack('a12', sprintf("%011o\0", $size))
            . pack('a12', sprintf("%011o\0", 0))
            . str_repeat(' ', 8)
            . pack('a1', '0')
            . pack('a100', '')
            . pack('a6', 'ustar')
            . pack('a2', '00')
            . str_repeat("\0", 247);

        $checksum = 0;
        for ($i = 0; $i < 512; $i++) {
            $checksum += ord($header[$i]);
        }

        return substr_replace($header, sprintf("%06o\0 ", $checksum), 148, 8);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
