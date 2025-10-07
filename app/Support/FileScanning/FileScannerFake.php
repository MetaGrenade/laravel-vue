<?php

namespace App\Support\FileScanning;

use Illuminate\Http\UploadedFile;

class FileScannerFake implements FileScanner
{
    /**
     * @var array<int, FileScanResult>
     */
    private array $results = [];

    public function pushClean(?string $message = null, array $meta = []): self
    {
        $this->results[] = FileScanResult::clean($message, $meta);

        return $this;
    }

    public function pushBlocked(?string $message = null, array $meta = []): self
    {
        $this->results[] = FileScanResult::blocked($message, $meta);

        return $this;
    }

    public function scan(UploadedFile $file): FileScanResult
    {
        if ($this->results !== []) {
            return array_shift($this->results);
        }

        return FileScanResult::clean();
    }
}
