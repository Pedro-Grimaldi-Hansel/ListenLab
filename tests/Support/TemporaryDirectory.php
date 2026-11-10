<?php
declare(strict_types=1);

namespace ListenLab\Tests\Support;

trait TemporaryDirectory
{
    protected string $temporary;

    protected function createTemporary(): void
    {
        $this->temporary = sys_get_temp_dir() . '/listenlab-test-' . bin2hex(random_bytes(10));
        mkdir($this->temporary, 0700, true);
    }

    protected function removeTemporary(): void
    {
        if (!isset($this->temporary) || !is_dir($this->temporary)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->temporary, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->temporary);
    }
}
