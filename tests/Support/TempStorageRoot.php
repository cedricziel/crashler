<?php

declare(strict_types=1);

namespace App\Tests\Support;

trait TempStorageRoot
{
    private ?string $tempStorageRoot = null;

    protected function tempStorageRoot(): string
    {
        if (null === $this->tempStorageRoot) {
            $this->tempStorageRoot = sys_get_temp_dir().'/crashler-test-'.bin2hex(random_bytes(8));
            if (!mkdir($this->tempStorageRoot, 0o700, true) && !is_dir($this->tempStorageRoot)) {
                throw new \RuntimeException('Failed to create temp storage root: '.$this->tempStorageRoot);
            }
        }

        return $this->tempStorageRoot;
    }

    /**
     * @after
     */
    protected function removeTempStorageRoot(): void
    {
        if (null !== $this->tempStorageRoot && is_dir($this->tempStorageRoot)) {
            self::rrmdir($this->tempStorageRoot);
        }
        $this->tempStorageRoot = null;
    }

    private static function rrmdir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }
            $path = $dir.'/'.$entry;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
