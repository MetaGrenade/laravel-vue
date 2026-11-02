<?php

namespace App\Support\Install;

/**
 * One thing the installer looked at. A `required` one that is not `ok` stops the installation; the others are advice.
 */
final readonly class Requirement
{
    public function __construct(
        public string $name,
        public bool $ok,
        public bool $required,
        public string $detail,
    ) {}
}
