<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Component\Filesystem\Filesystem;

trait TempStorageRoot
{
    private ?string $tempStorageRoot = null;
    private ?Filesystem $tempStorageFs = null;

    protected function tempStorageRoot(): string
    {
        if (null === $this->tempStorageRoot) {
            $this->tempStorageFs = new Filesystem();
            $this->tempStorageRoot = sys_get_temp_dir().'/crashler-test-'.bin2hex(random_bytes(8));
            $this->tempStorageFs->mkdir($this->tempStorageRoot, 0o700);
        }

        return $this->tempStorageRoot;
    }

    /**
     * @after
     */
    protected function removeTempStorageRoot(): void
    {
        if (null !== $this->tempStorageRoot && null !== $this->tempStorageFs && $this->tempStorageFs->exists($this->tempStorageRoot)) {
            $this->tempStorageFs->remove($this->tempStorageRoot);
        }
        $this->tempStorageRoot = null;
        $this->tempStorageFs = null;
    }
}
