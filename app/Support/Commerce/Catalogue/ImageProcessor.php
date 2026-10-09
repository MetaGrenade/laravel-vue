<?php

namespace App\Support\Commerce\Catalogue;

use GdImage;

/**
 * Turns an upload into the pictures the shop keeps.
 *
 * Nothing the customer-facing site serves is the file that was uploaded. The upload is decoded
 * as an image (so anything that is not one is refused, whatever it is called or claims to be),
 * then drawn again at each size and encoded afresh as WebP. Metadata (location, camera, an
 * embedded profile or script) does not survive that, and a file that is a picture and something
 * else at once (a "polyglot") only ever comes out as the picture.
 *
 * Before decoding, the size the file declares is checked against a pixel limit, because decoding
 * needs memory in proportion to the pixels, not the file size: a few kilobytes of PNG can describe
 * a picture that would exhaust the server.
 */
class ImageProcessor
{
    /** JPEG, PNG, GIF (its first frame) and WebP. SVG is not allowed: it is a document that can carry script. */
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP];

    /**
     * @throws ImageRejected
     */
    public function process(string $bytes): ProcessedImage
    {
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            throw new ImageRejected('This server cannot process images: the GD extension with WebP support is missing.');
        }

        [$width, $height, $type] = $this->inspect($bytes);

        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage) {
            throw new ImageRejected('That file could not be read as a picture. It may be damaged.');
        }

        $source = $this->oriented($source, $bytes, $type);

        $sizes = (array) config('commerce.images.sizes');

        $large = $this->fit($source, (int) ($sizes['large'] ?? 1600));
        $medium = $this->fit($source, (int) ($sizes['medium'] ?? 800));
        $thumb = $this->fit($source, (int) ($sizes['thumb'] ?? 320));

        return new ProcessedImage(
            large: $this->encode($large),
            medium: $this->encode($medium),
            thumb: $this->encode($thumb),
            width: imagesx($large),
            height: imagesy($large),
        );
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
            throw new ImageRejected('Upload a JPEG, PNG, WebP or GIF picture.');
        }

        [$width, $height] = $info;

        if ($width < 1 || $height < 1) {
            throw new ImageRejected('That picture has no size.');
        }

        $limit = (int) config('commerce.images.max_pixels', 16_000_000);

        if ($width * $height > $limit) {
            throw new ImageRejected(sprintf(
                'That picture is %s megapixels; the most allowed is %s. Make it smaller and try again.',
                number_format($width * $height / 1_000_000, 1),
                number_format($limit / 1_000_000, 1),
            ));
        }

        return [$width, $height, $info[2]];
    }

    /**
     * Turn a photo the right way up. Cameras store pictures as the sensor saw them and add a note
     * saying how to turn them; the note is dropped when the picture is encoded again, so the turn
     * has to be made first. (Needs the exif extension; without it the picture is kept as stored.)
     */
    private function oriented(GdImage $image, string $bytes, int $type): GdImage
    {
        if ($type !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data('data://image/jpeg;base64,'.base64_encode($bytes));
        $orientation = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $turned = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $turned instanceof GdImage ? $turned : $image;
    }

    /**
     * The picture scaled to fit in a square of the given side, keeping its proportions. A picture
     * that is already smaller is not enlarged.
     */
    private function fit(GdImage $image, int $longest): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $longest / max($width, $height));

        // Transparent areas (a cut-out product on a clear background) stay transparent.
        imagepalettetotruecolor($image);
        imagealphablending($image, false);
        imagesavealpha($image, true);

        if ($scale === 1) {
            return $image;
        }

        $scaled = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)), IMG_BICUBIC);

        if (! $scaled instanceof GdImage) {
            throw new ImageRejected('That picture could not be resized.');
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
            throw new ImageRejected('That picture could not be converted.');
        }

        return $data;
    }
}
