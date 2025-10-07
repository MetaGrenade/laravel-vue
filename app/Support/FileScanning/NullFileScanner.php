<?php

namespace App\Support\FileScanning;

use Illuminate\Http\UploadedFile;

class NullFileScanner implements FileScanner
{
    public function scan(UploadedFile $file): FileScanResult
    {
        return FileScanResult::clean('File scanning is disabled.');
    }
}
