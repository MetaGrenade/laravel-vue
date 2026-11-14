<?php

namespace App\Support\Commerce\Catalogue;

use App\Support\Install\PhpProbe;
use GdImage;
use Illuminate\Support\Facades\Log;

/**
 * Turns an upload into the pictures the shop keeps.
 *
 * Nothing the customer-facing site serves is the file that was uploaded. The upload is decoded
 * as an image (so anything that is not one is refused, whatever it is called or claims to be),
 * then drawn again at each size and encoded afresh as WebP. Metadata (location, camera, an
 * embedded profile or script) does not survive that, and a file that is a picture and something
 * else at once (a "polyglot") only ever comes out as the picture.
 *
 * Before decoding, the size the file declares is checked twice, because decoding needs memory in
 * proportion to the pixels, not the file size (a few kilobytes of PNG can describe a picture that
 * would exhaust the server): against a pixel limit, and against the memory PHP has left. A picture
 * that would not fit is refused with a message. Running out of memory is not an exception that can
 * be caught: PHP stops, and the person who sent the picture is shown a server error.
 */
class ImageProcessor
{
    /** JPEG, PNG, GIF (its first frame) and WebP. SVG is not allowed: it is a document that can carry script. */
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /**
     * The memory it takes to draw a picture is counted as this many bytes for each pixel it declares (width
     * times height), and {@see self::FIXED_BYTES} on top.
     *
     * GD keeps a picture at 4 bytes a pixel and needs a second copy of it while it is scaled, turned or
     * encoded: 8 for a big picture. Measured on PHP 8.4, the most is 8.2 for one that is kept at its own size
     * (the support center) and 7.1 for one that is drawn at the shop's sizes
     * (docs/images.md#how-much-memory-a-picture-needs).
     */
    public const BYTES_PER_PIXEL = 8;

    /**
     * What drawing a picture costs whatever its size: the 1600 pixel picture that is made and encoded
     * (10 MB, and as much again to encode it) and the scaling's own working copy. A picture of 2000 by
     * 2000 takes 46 MB, which is 11 bytes a pixel, though one of 4000 by 4000 takes 7. Counted as 24 MiB,
     * a little over what it is, so that a picture that only just fits does not run out of room all the same.
     */
    public const FIXED_BYTES = 24 * 1048576;

    public function __construct(private readonly PhpProbe $php = new PhpProbe) {}

    /**
     * The memory to count on for drawing a picture of this many pixels.
     */
    public static function memoryFor(int $pixels): int
    {
        return $pixels * self::BYTES_PER_PIXEL + self::FIXED_BYTES;
    }

    /**
     * The most pixels a picture may have when this much memory is left for it.
     */
    public static function pixelsFor(int $room): int
    {
        return intdiv(max(0, $room - self::FIXED_BYTES), self::BYTES_PER_PIXEL);
    }

    /**
     * @throws ImageRejected
     */
    public function process(string $bytes): ProcessedImage
    {
        [$source, $turn] = $this->decode($bytes);

        $sizes = (array) config('commerce.images.sizes');

        // Each size is drawn from the whole picture and turned upright afterwards, when it is small:
        // a quarter turn of the whole picture would need a second copy of it.
        $large = $this->turned($this->fit($source, (int) ($sizes['large'] ?? 1600)), $turn);
        $medium = $this->turned($this->fit($source, (int) ($sizes['medium'] ?? 800)), $turn);
        $thumb = $this->turned($this->fit($source, (int) ($sizes['thumb'] ?? 320)), $turn);

        return new ProcessedImage(
            large: $this->encode($large),
            medium: $this->encode($medium),
            thumb: $this->encode($thumb),
            width: imagesx($large),
            height: imagesy($large),
        );
    }

    /**
     * One WebP whose longest side is at most $longest. A smaller picture is not enlarged.
     *
     * @throws ImageRejected
     */
    public function webp(string $bytes, int $longest): string
    {
        [$source, $turn] = $this->decode($bytes);

        $picture = $this->turned($this->fit($source, max(1, $longest)), $turn);

        // A picture kept at its own size is turned as a copy of the whole, and the whole is not needed while the copy
        // is encoded (which takes as much memory again): 187 MB became 125 for a 16 megapixel photo taken sideways.
        unset($source);

        return $this->encode($picture);
    }

    /**
     * One WebP whose longest side is at most $longest, and the same picture again at each of the given
     * widths, for a picture that is shown across a column of the page (a blog cover). Decoded once. A width
     * is measured on the large picture, not the upload, and never enlarges it: a narrow picture comes back
     * the same at every width.
     *
     * @param  list<int>  $widths
     * @return array{large: string, width: int, height: int, widths: array<int, string>} The size in pixels is the large picture's. The widths are keys, as given.
     *
     * @throws ImageRejected
     */
    public function webpAtWidths(string $bytes, int $longest, array $widths): array
    {
        [$source, $turn] = $this->decode($bytes);

        $large = $this->turned($this->fit($source, max(1, $longest)), $turn);

        $encoded = ['large' => $this->encode($large), 'width' => imagesx($large), 'height' => imagesy($large), 'widths' => []];

        foreach ($widths as $width) {
            $encoded['widths'][$width] = $this->encode($this->fitWidth($large, max(1, $width)));
        }

        return $encoded;
    }

    /**
     * @return array{0: GdImage, 1: int} The picture as it was stored, and the turn (in degrees, as GD counts them: anticlockwise) that makes it upright.
     *
     * @throws ImageRejected
     */
    private function decode(string $bytes): array
    {
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            throw new ImageRejected('This server cannot process images: the GD extension with WebP support is missing.');
        }

        [, , $type] = $this->inspect($bytes);

        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage) {
            throw new ImageRejected(__('forms.images.unreadable'));
        }

        return [$source, $this->turnToUpright($bytes, $type)];
    }

    /**
     * What the file says it is, before anything is decoded.
     *
     * @return array{0: int, 1: int, 2: int} Width, height and image type.
     *
     * @throws ImageRejected
     */
    private function inspect(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false || ! in_array($info[2], self::TYPES, true)) {
            throw new ImageRejected(__('forms.images.type'));
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1) {
            throw new ImageRejected(__('forms.images.no_size'));
        }

        $limit = (int) config('commerce.images.max_pixels', 16_000_000);

        if ($width * $height > $limit) {
            throw new ImageRejected(__('forms.images.too_large', [
                'size' => number_format($width * $height / 1_000_000, 1),
                'max' => number_format($limit / 1_000_000, 1),
            ]));
        }

        $this->mustFitInMemory($width, $height);

        return [$width, $height, $info[2]];
    }

    /**
     * Refuse a picture that PHP does not have the memory to draw, before it is decoded. PHP's memory
     * limit is a setting of the web server (and of the queue worker, which can differ): 128 MB is what
     * a stock php.ini has, and a 12 megapixel photo taken with a phone does not fit in that.
     *
     * @throws ImageRejected
     */
    private function mustFitInMemory(int $width, int $height): void
    {
        $limit = $this->php->bytes('memory_limit');

        // -1 is "no limit"; a setting that is not a size is not one that can be held against the picture.
        if ($limit === null || $limit <= 0) {
            return;
        }

        $room = $limit - $this->php->memoryInUse();
        $pixels = $width * $height;

        if (self::memoryFor($pixels) <= $room) {
            return;
        }

        $fits = self::pixelsFor($room);

        // Whoever sent the picture cannot change this, so the person who runs the site is told why it was refused.
        Log::warning('A picture was refused because PHP does not have the memory to draw it. Raise memory_limit in the php.ini of the web server and the queue worker (the Docker image sets 256M).', [
            'megapixels' => round($pixels / 1_000_000, 1),
            'room_megapixels' => round($fits / 1_000_000, 1),
            'memory_limit' => $limit,
        ]);

        throw new ImageRejected(__('forms.images.too_large_here', [
            'size' => number_format($pixels / 1_000_000, 1),
            'max' => number_format($fits / 1_000_000, 1),
        ]));
    }

    /**
     * How far a photo has to be turned to be the right way up. Cameras store pictures as the sensor
     * saw them and add a note saying how to turn them; the note is dropped when the picture is
     * encoded again, so the turn has to be made. (Needs the exif extension; without it the picture
     * is kept as stored.)
     */
    private function turnToUpright(string $bytes, int $type): int
    {
        if ($type !== IMAGETYPE_JPEG || ! $this->php->extensionLoaded('exif')) {
            return 0;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        return match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
    }

    /**
     * The picture turned by the given number of degrees (anticlockwise). Done on a picture that has
     * already been made small: turning needs a second copy of it.
     */
    private function turned(GdImage $image, int $degrees): GdImage
    {
        if ($degrees === 0) {
            return $image;
        }

        $turned = imagerotate($image, $degrees, 0);

        if (! $turned instanceof GdImage) {
            return $image;
        }

        imagealphablending($turned, false);
        imagesavealpha($turned, true);

        return $turned;
    }

    /**
     * The picture scaled to fit in a square of the given side, keeping its proportions. A picture
     * that is already smaller is not enlarged.
     */
    private function fit(GdImage $image, int $longest): GdImage
    {
        return $this->scaled($image, min(1, $longest / max(imagesx($image), imagesy($image))));
    }

    /**
     * The picture scaled to the given width, keeping its proportions. A picture that is already narrower
     * is not enlarged.
     */
    private function fitWidth(GdImage $image, int $width): GdImage
    {
        return $this->scaled($image, min(1, $width / imagesx($image)));
    }

    /**
     * @param  float|int  $scale  At most 1: pictures are made smaller here, never larger.
     */
    private function scaled(GdImage $image, float|int $scale): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        // Transparent areas (a cut-out product on a clear background) stay transparent.
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        if ($scale >= 1) {
            return $image;
        }

        $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);

        if (! $scaled instanceof GdImage) {
            throw new ImageRejected(__('forms.images.resize_failed'));
        }

        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);

        return $scaled;
    }

    private function encode(GdImage $image): string
    {
        ob_start();
        $written = imagewebp($image, null, (int) config('commerce.images.quality', 82));
        $data = (string) ob_get_clean();

        if (! $written || $data === '') {
            throw new ImageRejected(__('forms.images.convert_failed'));
        }

        return $data;
    }
}
