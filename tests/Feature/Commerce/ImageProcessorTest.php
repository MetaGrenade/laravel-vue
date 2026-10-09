<?php

namespace Tests\Feature\Commerce;

use App\Support\Commerce\Catalogue\ImageProcessor;
use App\Support\Commerce\Catalogue\ImageRejected;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the shop keeps of an upload. Nothing it serves is the uploaded file: it is decoded as a
 * picture and drawn again, so what is not a picture is refused and what hides in one is lost.
 */
class ImageProcessorTest extends TestCase
{
    private ImageProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = new ImageProcessor;
    }

    /**
     * A plain picture of the given kind.
     */
    private function picture(string $type, int $width, int $height, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);

        if ($transparent) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        } else {
            imagefill($image, 0, 0, imagecolorallocate($image, 200, 80, 40));
        }

        ob_start();
        match ($type) {
            'png' => imagepng($image),
            'jpeg' => imagejpeg($image),
            'gif' => imagegif($image),
            'webp' => imagewebp($image),
            'bmp' => imagebmp($image),
        };

        return (string) ob_get_clean();
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function inspect(string $bytes): array
    {
        $info = getimagesizefromstring($bytes);
        $this->assertNotFalse($info, 'the result is a picture');

        return [$info[0], $info[1], $info[2]];
    }

    // --- What is kept -----------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function accepted(): array
    {
        return ['JPEG' => ['jpeg'], 'PNG' => ['png'], 'GIF' => ['gif'], 'WebP' => ['webp']];
    }

    #[Test]
    #[DataProvider('accepted')]
    public function a_picture_is_kept_as_webp_at_three_sizes(string $type): void
    {
        $result = $this->processor->process($this->picture($type, 3000, 2000));

        $this->assertSame([1600, 1067, IMAGETYPE_WEBP], $this->inspect($result->large));
        $this->assertSame([800, 533, IMAGETYPE_WEBP], $this->inspect($result->medium));
        $this->assertSame([320, 213, IMAGETYPE_WEBP], $this->inspect($result->thumb));
        $this->assertSame([1600, 1067], [$result->width, $result->height]);
    }

    #[Test]
    public function a_tall_picture_is_fitted_by_its_longest_side(): void
    {
        $result = $this->processor->process($this->picture('png', 1000, 3000));

        $this->assertSame([533, 1600], array_slice($this->inspect($result->large), 0, 2));
        $this->assertSame([107, 320], array_slice($this->inspect($result->thumb), 0, 2));
    }

    #[Test]
    public function a_small_picture_is_never_enlarged(): void
    {
        $result = $this->processor->process($this->picture('png', 200, 100));

        foreach ([$result->large, $result->medium, $result->thumb] as $size) {
            $this->assertSame([200, 100], array_slice($this->inspect($size), 0, 2));
        }
    }

    #[Test]
    public function the_sizes_follow_the_configuration(): void
    {
        config(['commerce.images.sizes' => ['large' => 500, 'medium' => 250, 'thumb' => 100]]);

        $result = $this->processor->process($this->picture('png', 1000, 500));

        $this->assertSame([500, 250], array_slice($this->inspect($result->large), 0, 2));
        $this->assertSame([250, 125], array_slice($this->inspect($result->medium), 0, 2));
        $this->assertSame([100, 50], array_slice($this->inspect($result->thumb), 0, 2));
    }

    #[Test]
    public function transparency_is_kept(): void
    {
        $result = $this->processor->process($this->picture('png', 400, 400, transparent: true));

        $decoded = imagecreatefromstring($result->large);
        $alpha = (imagecolorat($decoded, 10, 10) >> 24) & 0x7F;

        $this->assertGreaterThan(100, $alpha, 'a clear background stays clear');
    }

    // --- What is lost -----------------------------------------------------------------------------

    /**
     * A PNG with an extra text chunk, the way a tool leaves a note (or something worse) in a file.
     */
    private function pngWithNote(string $note): string
    {
        $png = $this->picture('png', 64, 64);
        $chunk = 'tEXt'.'Comment'."\0".$note;
        $text = pack('N', strlen($chunk) - 4).$chunk.pack('N', crc32($chunk));

        // Just before the closing IEND chunk (12 bytes).
        return substr($png, 0, -12).$text.substr($png, -12);
    }

    #[Test]
    public function metadata_does_not_survive(): void
    {
        $upload = $this->pngWithNote('GPS-LOCATION-51.5,-0.12 camera-serial-998877');

        $this->assertStringContainsString('GPS-LOCATION', $upload, 'the test file really carries the note');

        $result = $this->processor->process($upload);

        foreach ([$result->large, $result->medium, $result->thumb] as $size) {
            $this->assertStringNotContainsString('GPS-LOCATION', $size);
            $this->assertStringNotContainsString('camera-serial', $size);
        }
    }

    #[Test]
    public function a_picture_with_a_script_appended_comes_out_as_only_a_picture(): void
    {
        $polyglot = $this->picture('png', 64, 64).'<?php system($_GET["c"]); ?><script>alert(1)</script>';

        $result = $this->processor->process($polyglot);

        foreach ([$result->large, $result->medium, $result->thumb] as $size) {
            $this->assertStringNotContainsString('<?php', $size);
            $this->assertStringNotContainsString('<script', $size);
            $this->assertSame(IMAGETYPE_WEBP, $this->inspect($size)[2]);
        }
    }

    // --- What is refused --------------------------------------------------------------------------

    /**
     * @return array<string, array{0: string}>
     */
    public static function notPictures(): array
    {
        return [
            'an SVG, which is a document that can carry script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'HTML named like a picture' => ['<html><body><script>alert(1)</script></body></html>'],
            'PHP' => ['<?php echo "pwned";'],
            'plain text' => ['Hello, I am not a picture.'],
            'nothing at all' => [''],
            'random bytes' => ["\x00\x01\x02\x03\xff\xfe\xfd garbage"],
        ];
    }

    #[Test]
    #[DataProvider('notPictures')]
    public function something_that_is_not_a_picture_is_refused(string $contents): void
    {
        $this->expectException(ImageRejected::class);
        $this->expectExceptionMessage('Upload a JPEG, PNG, WebP or GIF picture.');

        $this->processor->process($contents);
    }

    #[Test]
    public function a_picture_of_a_kind_the_shop_does_not_keep_is_refused(): void
    {
        $this->expectException(ImageRejected::class);
        $this->expectExceptionMessage('Upload a JPEG, PNG, WebP or GIF picture.');

        $this->processor->process($this->picture('bmp', 50, 50));
    }

    #[Test]
    public function a_damaged_picture_is_refused(): void
    {
        $png = $this->picture('png', 200, 200);

        $this->expectException(ImageRejected::class);
        $this->expectExceptionMessage('could not be read');

        // The header is intact (so it declares a size) but the picture itself is cut short.
        $this->processor->process(substr($png, 0, 60));
    }

    #[Test]
    public function a_picture_with_too_many_pixels_is_refused_before_it_is_decoded(): void
    {
        config(['commerce.images.max_pixels' => 4_000_000]);

        try {
            $this->processor->process($this->picture('png', 2100, 2000));
            $this->fail('A picture over the pixel limit was accepted.');
        } catch (ImageRejected $exception) {
            $this->assertSame('That picture is 4.2 megapixels; the most allowed is 4.0. Make it smaller and try again.', $exception->getMessage());
        }
    }

    #[Test]
    public function a_small_file_that_declares_a_huge_picture_is_refused(): void
    {
        // A PNG header claiming 60000 x 60000 pixels (3.6 billion): decoding it would need over 14 GB.
        // The file is tiny; only its declared size gives it away.
        $png = $this->picture('png', 8, 8);
        $bomb = substr($png, 0, 16).pack('N', 60000).pack('N', 60000).substr($png, 24);

        $this->expectException(ImageRejected::class);
        $this->expectExceptionMessage('megapixels');

        $this->processor->process($bomb);
    }

    #[Test]
    public function a_picture_exactly_at_the_limit_is_accepted(): void
    {
        config(['commerce.images.max_pixels' => 10_000]);

        $result = $this->processor->process($this->picture('png', 100, 100));

        $this->assertSame([100, 100], [$result->width, $result->height]);
    }
}
