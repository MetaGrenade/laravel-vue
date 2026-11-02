<?php

/**
 * Makes the brand files in public/ from the two drawings in resources/brand/ (resources/brand/README.md):
 *
 *     php scripts/build-brand-assets.php
 *
 * The wordmarks are the drawings at 1024 x 341, with the faint haze around them taken out and the nearly solid pixels made solid (the
 * drawings are saved about 94% opaque, which turns white lettering light grey on a dark page). The mark (the M and the gem) is cut out of
 * the light drawing and drawn again at each size of the icons. Images are made smaller by averaging, with each pixel counted by how
 * opaque it is: the drawings have transparent edges, and a plain resize blends their colour with the transparent pixels' black and
 * leaves a dark fringe (a grey one on the white lettering of the dark logo).
 */
if (! extension_loaded('gd') || ! function_exists('imagecreatefromwebp')) {
    fwrite(STDERR, "This needs PHP's GD extension with WebP support.\n");
    exit(1);
}

ini_set('memory_limit', '512M');

const ROOT = __DIR__.'/..';

/** A pixel under this opacity (0 to 1) is taken out of a drawing. */
const HAZE = 0.08;

/** A pixel at or over this opacity is made fully opaque. */
const SOLID = 0.9;

/** The empty space around the mark in an icon, as a share of the mark's larger side. */
const MARK_MARGIN = 0.12;

/** What is left of a pixel that is not there: fully transparent, in the form GD keeps it (alpha 127 is transparent, 0 opaque). */
const NOTHING = 0x7F000000;

/**
 * A drawing as one integer per pixel, left to right and top to bottom, in GD's own form (alpha, red, green, blue).
 *
 * @return array{width: int, height: int, pixels: list<int>}
 */
function load(string $path): array
{
    $image = imagecreatefromwebp($path);
    $width = imagesx($image);
    $height = imagesy($image);
    $pixels = [];

    for ($y = 0; $y < $height; $y++) {
        for ($x = 0; $x < $width; $x++) {
            $color = imagecolorat($image, $x, $y);
            $opacity = 1 - (($color >> 24) & 127) / 127;
            $pixels[] = $opacity < HAZE ? NOTHING : ($opacity >= SOLID ? $color & 0x00FFFFFF : $color);
        }
    }

    return ['width' => $width, 'height' => $height, 'pixels' => $pixels];
}

/**
 * The first shape from the left (the mark): the columns with something solid in them, until a gap wider than a few pixels.
 *
 * @param  array{width: int, height: int, pixels: list<int>}  $drawing
 * @return array{int, int, int, int} Left, top, width and height.
 */
function markBox(array $drawing): array
{
    ['width' => $width, 'height' => $height, 'pixels' => $pixels] = $drawing;
    $left = $right = null;
    $top = $height;
    $bottom = 0;
    $gap = 0;

    for ($x = 0; $x < $width; $x++) {
        $solid = false;

        for ($y = 0; $y < $height; $y++) {
            if ((($pixels[$y * $width + $x] >> 24) & 127) < 64) {
                $solid = true;
                $top = min($top, $y);
                $bottom = max($bottom, $y);
            }
        }

        if ($solid) {
            $left ??= $x;
            $right = $x;
            $gap = 0;
        } elseif ($left !== null && ++$gap > 24) {
            break;
        }
    }

    return [$left, $top, $right - $left + 1, $bottom - $top + 1];
}

/**
 * A box of a drawing, drawn at a new size, as a PNG. Each new pixel is the average of the pixels it covers, weighted by how opaque
 * they are. A box may reach past the drawing; what is outside is transparent.
 *
 * @param  array{width: int, height: int, pixels: list<int>}  $drawing
 * @param  array{float, float, float, float}  $box  Left, top, width and height, in the drawing's pixels.
 */
function draw(array $drawing, array $box, int $width, int $height): string
{
    ['width' => $sourceWidth, 'height' => $sourceHeight, 'pixels' => $pixels] = $drawing;
    [$left, $top, $boxWidth, $boxHeight] = $box;
    $scaleX = $boxWidth / $width;
    $scaleY = $boxHeight / $height;

    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, NOTHING);

    for ($j = 0; $j < $height; $j++) {
        $y0 = $top + $j * $scaleY;
        $y1 = $y0 + $scaleY;

        for ($i = 0; $i < $width; $i++) {
            $x0 = $left + $i * $scaleX;
            $x1 = $x0 + $scaleX;
            $weight = $opacity = $red = $green = $blue = 0.0;

            for ($y = (int) floor($y0); $y < $y1; $y++) {
                $wy = min($y1, $y + 1) - max($y0, $y);

                for ($x = (int) floor($x0); $x < $x1; $x++) {
                    $w = $wy * (min($x1, $x + 1) - max($x0, $x));
                    $weight += $w;

                    if ($x < 0 || $y < 0 || $x >= $sourceWidth || $y >= $sourceHeight) {
                        continue;
                    }

                    $color = $pixels[$y * $sourceWidth + $x];
                    $o = $w * (1 - (($color >> 24) & 127) / 127);
                    $opacity += $o;
                    $red += $o * (($color >> 16) & 255);
                    $green += $o * (($color >> 8) & 255);
                    $blue += $o * ($color & 255);
                }
            }

            if ($opacity <= 0.0) {
                continue;
            }

            $alpha = (int) round((1 - $opacity / $weight) * 127);
            imagesetpixel($image, $i, $j, ($alpha << 24) | ((int) round($red / $opacity) << 16) | ((int) round($green / $opacity) << 8) | (int) round($blue / $opacity));
        }
    }

    ob_start();
    imagepng($image, null, 9);

    return (string) ob_get_clean();
}

/**
 * An .ico file holding these PNGs (size => bytes). Every browser that still asks for /favicon.ico reads PNG inside it.
 *
 * @param  array<int, string>  $pngs
 */
function ico(array $pngs): string
{
    $entries = '';
    $data = '';
    $offset = 6 + 16 * count($pngs);

    foreach ($pngs as $size => $bytes) {
        $entries .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($bytes), $offset);
        $data .= $bytes;
        $offset += strlen($bytes);
    }

    return pack('vvv', 0, 1, count($pngs)).$entries.$data;
}

function save(string $file, string $bytes): void
{
    file_put_contents(ROOT.'/public/'.$file, $bytes);
    printf("  public/%-30s %7d bytes\n", $file, strlen($bytes));
}

echo "Reading resources/brand/\n";
$light = load(ROOT.'/resources/brand/logo-light.webp');
$dark = load(ROOT.'/resources/brand/logo-dark.webp');

echo "Writing\n";
foreach (['light' => $light, 'dark' => $dark] as $name => $drawing) {
    save("brand/logo-{$name}.png", draw($drawing, [0, 0, $drawing['width'], $drawing['height']], 1024, 341));
}

// The mark: a square around the M and the gem, with some space around it.
[$markLeft, $markTop, $markWidth, $markHeight] = markBox($light);
$side = max($markWidth, $markHeight) * (1 + MARK_MARGIN);
$square = [$markLeft + $markWidth / 2 - $side / 2, $markTop + $markHeight / 2 - $side / 2, $side, $side];
printf("  the mark is %dx%d at %d,%d in the drawing, and the icons are cut from a %dx%d square around it\n", $markWidth, $markHeight, $markLeft, $markTop, $side, $side);

$icon = static fn (int $size): string => draw($light, $square, $size, $size);

save('brand/mark.png', $icon(192));
save('apple-touch-icon.png', $icon(180));
save('android-chrome-192x192.png', $icon(192));
save('android-chrome-512x512.png', $icon(512));
save('favicon-32x32.png', $icon(32));
save('favicon-16x16.png', $icon(16));
save('favicon.ico', ico([16 => $icon(16), 32 => $icon(32), 48 => $icon(48)]));
