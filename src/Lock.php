<?php

declare(strict_types=1);

namespace App;

final class Lock
{
    private string $lockFile;
    /** @var resource|null */
    private $handle = null;

    public function __construct(string $lockDir, string $name)
    {
        if (!is_dir($lockDir)) {
            mkdir($lockDir, 0755, true);
        }
        $this->lockFile = rtrim($lockDir, '/') . '/' . $name . '.lock';
    }

    public function acquire(): bool
    {
        $this->handle = fopen($this->lockFile, 'c');
        if ($this->handle === false) {
            return false;
        }

        if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
            fclose($this->handle);
            $this->handle = null;
            return false;
        }

        ftruncate($this->handle, 0);
        fwrite($this->handle, (string)getmypid());
        fflush($this->handle);

        return true;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
            @unlink($this->lockFile);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
