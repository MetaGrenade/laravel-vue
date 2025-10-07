<?php

namespace App\Support\FileScanning;

use Illuminate\Http\UploadedFile;

interface FileScanner
{
    public function scan(UploadedFile $file): FileScanResult;
}
