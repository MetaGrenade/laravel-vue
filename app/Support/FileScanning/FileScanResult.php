<?php

namespace App\Support\FileScanning;

class FileScanResult
{
    public const STATUS_CLEAN = 'clean';
    public const STATUS_BLOCKED = 'blocked';

    public function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly array $meta = [],
    ) {
    }

    public static function clean(?string $message = null, array $meta = []): self
    {
        return new self(self::STATUS_CLEAN, $message, $meta);
    }

    public static function blocked(?string $message = null, array $meta = []): self
    {
        return new self(self::STATUS_BLOCKED, $message, $meta);
    }

    public function isClean(): bool
    {
        return $this->status === self::STATUS_CLEAN;
    }

    public function isBlocked(): bool
    {
        return $this->status === self::STATUS_BLOCKED;
    }
}
