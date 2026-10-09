<?php

namespace App\Support\Commerce\Catalogue;

/**
 * An upload after it has been decoded and encoded again at each size.
 */
final readonly class ProcessedImage
{
    /**
     * @param  string  $large  Encoded image data.
     * @param  string  $medium  Encoded image data.
     * @param  string  $thumb  Encoded image data.
     * @param  int  $width  Of the large size.
     * @param  int  $height  Of the large size.
     */
    public function __construct(
        public string $large,
        public string $medium,
        public string $thumb,
        public int $width,
        public int $height,
    ) {}
}
