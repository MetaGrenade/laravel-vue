<?php

return [
    'driver' => env('FILE_SCANNER_DRIVER', 'null'),
    'quarantine_disk' => env('FILE_SCANNER_QUARANTINE_DISK', 'local'),
    'quarantine_path' => env('FILE_SCANNER_QUARANTINE_PATH', 'quarantine/support-attachments'),
];
