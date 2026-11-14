<?php

namespace Tests\Feature\Commerce;

use App\Support\Commerce\Catalogue\ImageProcessor;
use App\Support\Commerce\Catalogue\ImageRejected;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakePhpProbe;
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

    // --- Photos taken sideways --------------------------------------------------------------------

    /**
     * A landscape JPEG whose top-left corner is red and the rest blue, carrying an EXIF note that says how the camera was held
     * (1 upright, 3 upside down, 6 a quarter turn clockwise to be upright, 8 a quarter turn the other way).
     */
    private function photo(int $orientation, int $width = 300, int $height = 200): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 255));
        imagefilledrectangle($image, 0, 0, (int) ($width / 5), (int) ($height / 5), imagecolorallocate($image, 255, 0, 0));

        ob_start();
        imagejpeg($image, null, 95);
        $jpeg = (string) ob_get_clean();

        // The smallest EXIF block there is: one entry, the orientation, straight after the start-of-image marker.
        $tiff = "II*\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00".pack('v', $orientation)."\x00\x00\x00\x00\x00\x00";
        $payload = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    /**
     * Which corner of a picture is red: 'top left', 'top right', 'bottom left' or 'bottom right'.
     */
    private function redCorner(string $webp): string
    {
        $image = imagecreatefromstring($webp);
        $width = imagesx($image);
        $height = imagesy($image);

        $isRed = function (int $x, int $y) use ($image): bool {
            $colour = imagecolorat($image, $x, $y);

            return (($colour >> 16) & 0xFF) > 180 && ($colour & 0xFF) < 90;
        };

        $inset = (int) (min($width, $height) / 20) + 1;

        foreach ([
            'top left' => [$inset, $inset],
            'top right' => [$width - $inset - 1, $inset],
            'bottom left' => [$inset, $height - $inset - 1],
            'bottom right' => [$width - $inset - 1, $height - $inset - 1],
        ] as $corner => [$x, $y]) {
            if ($isRed($x, $y)) {
                return $corner;
            }
        }

        return 'none';
    }

    /**
     * @return array<string, array{0: int, 1: string, 2: int, 3: int}>
     */
    public static function turned(): array
    {
        return [
            'upright is left as it is' => [1, 'top left', 300, 200],
            'upside down is turned half way round' => [3, 'bottom right', 300, 200],
            'a quarter turn clockwise puts the corner top right' => [6, 'top right', 200, 300],
            'a quarter turn the other way puts the corner bottom left' => [8, 'bottom left', 200, 300],
        ];
    }

    /**
     * Turning a photo upright needs the exif extension, which is advice and not a condition (docs/install.md): without it a
     * photo is kept as it was stored, and `without_the_exif_extension_a_photo_is_kept_as_it_was_stored` says so on every machine.
     * A test of the turn itself can only run where the extension is.
     */
    private function requireExifToTurn(int $orientation = 6): void
    {
        if ($orientation > 1 && ! extension_loaded('exif')) {
            $this->markTestSkipped('The exif extension is not loaded here, so a photo taken sideways is kept as stored.');
        }
    }

    #[Test]
    #[DataProvider('turned')]
    public function a_photo_taken_sideways_is_turned_upright_at_every_size(int $orientation, string $corner, int $width, int $height): void
    {
        $this->requireExifToTurn($orientation);

        config(['commerce.images.sizes' => ['large' => 250, 'medium' => 150, 'thumb' => 100]]);

        $result = $this->processor->process($this->photo($orientation));

        $scale = 250 / 300;

        foreach ([$result->large, $result->medium, $result->thumb] as $size) {
            $this->assertSame($corner, $this->redCorner($size));
        }

        // The longest side is 250, 150 and 100: the picture is the right way up in proportion too.
        $this->assertSame([(int) round($width * $scale), (int) round($height * $scale)], [$result->width, $result->height]);
        $this->assertSame($width > $height, $result->width > $result->height);
    }

    #[Test]
    #[DataProvider('turned')]
    public function one_webp_of_a_photo_taken_sideways_is_turned_upright(int $orientation, string $corner, int $width, int $height): void
    {
        $this->requireExifToTurn($orientation);

        $webp = $this->processor->webp($this->photo($orientation), 1000);

        $this->assertSame($corner, $this->redCorner($webp));
        $this->assertSame([$width, $height], array_slice($this->inspect($webp), 0, 2), 'a picture that is already small is not enlarged');
    }

    #[Test]
    #[DataProvider('turned')]
    public function every_width_of_a_photo_taken_sideways_is_turned_upright(int $orientation, string $corner, int $width, int $height): void
    {
        $this->requireExifToTurn($orientation);

        $result = (new ImageProcessor)->webpAtWidths($this->photo($orientation), 250, [150, 80]);

        $this->assertSame($corner, $this->redCorner($result['large']));
        $this->assertSame($corner, $this->redCorner($result['widths'][150]));
        $this->assertSame($corner, $this->redCorner($result['widths'][80]));
        $this->assertSame($width > $height, $result['width'] > $result['height']);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function howItWasHeld(): array
    {
        return ['upright' => [1], 'upside down' => [3], 'a quarter turn clockwise' => [6], 'a quarter turn the other way' => [8]];
    }

    #[Test]
    #[DataProvider('howItWasHeld')]
    public function without_the_exif_extension_a_photo_is_kept_as_it_was_stored(int $orientation): void
    {
        // A machine without the extension is described to the processor, so this runs, and means the same, on every machine.
        $php = new FakePhpProbe;
        $php->missingExtensions = ['exif'];
        $processor = new ImageProcessor($php);
        $photo = $this->photo($orientation);

        $result = $processor->process($photo);

        $this->assertSame('top left', $this->redCorner($result->large));
        $this->assertSame('top left', $this->redCorner($result->thumb));
        $this->assertSame([300, 200], [$result->width, $result->height], 'still landscape, as it was stored');

        $webp = $processor->webp($photo, 1000);

        $this->assertSame('top left', $this->redCorner($webp));
        $this->assertSame([300, 200], array_slice($this->inspect($webp), 0, 2));

        $covers = $processor->webpAtWidths($photo, 250, [150, 80]);

        $this->assertSame('top left', $this->redCorner($covers['large']));
        $this->assertSame('top left', $this->redCorner($covers['widths'][150]));
        $this->assertSame('top left', $this->redCorner($covers['widths'][80]));
        $this->assertGreaterThan($covers['height'], $covers['width']);
    }

    #[Test]
    public function the_processor_asks_the_machine_whether_it_has_the_exif_extension(): void
    {
        $this->requireExifToTurn();

        // The same photo, on a machine that says it has the extension and one that says it has not.
        $has = new ImageProcessor(new FakePhpProbe);
        $hasNot = new ImageProcessor(tap(new FakePhpProbe, fn (FakePhpProbe $php) => $php->missingExtensions = ['exif']));
        $photo = $this->photo(6);

        $this->assertSame('top right', $this->redCorner($has->webp($photo, 1000)));
        $this->assertSame('top left', $this->redCorner($hasNot->webp($photo, 1000)));
    }

    #[Test]
    public function a_photo_with_no_note_about_how_it_was_held_is_left_as_it_is(): void
    {
        $image = imagecreatetruecolor(300, 200);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 255));
        imagefilledrectangle($image, 0, 0, 60, 40, imagecolorallocate($image, 255, 0, 0));
        ob_start();
        imagejpeg($image, null, 95);

        $this->assertSame('top left', $this->redCorner($this->processor->webp((string) ob_get_clean(), 1000)));
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

    // --- The memory PHP has ------------------------------------------------------------------------

    /**
     * A small PNG whose header claims the given size. It is read for its declared size and nothing else:
     * if the picture were decoded, the cut-off data would be refused as damaged instead.
     */
    private function claiming(int $width, int $height): string
    {
        $png = $this->picture('png', 8, 8);

        return substr($png, 0, 16).pack('N', $width).pack('N', $height).substr($png, 24);
    }

    /**
     * A processor on a machine whose PHP may use $limit bytes and has used $inUse of them.
     */
    private function onAMachineWith(?int $limit, int $inUse = 40 * 1048576): ImageProcessor
    {
        $php = new FakePhpProbe;
        $php->bytes = ['memory_limit' => $limit];
        $php->memoryInUse = $inUse;

        // The fake says the machine has everything; a JPEG would then be read for its EXIF note, which needs the real extension.
        if (! extension_loaded('exif')) {
            $php->missingExtensions[] = 'exif';
        }

        return new ImageProcessor($php);
    }

    #[Test]
    public function a_picture_the_memory_cannot_hold_is_refused_and_the_message_says_what_would_fit(): void
    {
        // 128 MB with 40 MB used leaves 88 MB; 24 MB of that goes on what every picture costs, and the rest is
        // room for 8.4 million pixels at 8 bytes each. A 12 megapixel phone photo is not.
        $processor = $this->onAMachineWith(128 * 1048576);
        $photo = $this->claiming(4000, 3000);

        foreach ([
            'process' => fn () => $processor->process($photo),
            'webp' => fn () => $processor->webp($photo, 1600),
            'webpAtWidths' => fn () => $processor->webpAtWidths($photo, 1600, [960, 480]),
        ] as $entry => $attempt) {
            try {
                $attempt();
                $this->fail("{$entry} accepted a picture that does not fit in memory.");
            } catch (ImageRejected $exception) {
                $this->assertSame('That picture is 12.0 megapixels; this server has the memory for about 8.4. Make it smaller and try again.', $exception->getMessage(), $entry);
            }
        }
    }

    #[Test]
    public function a_picture_is_accepted_when_it_only_just_fits_and_refused_a_byte_short(): void
    {
        $inUse = 40 * 1048576;
        $needed = ImageProcessor::memoryFor(100 * 100);
        $picture = $this->picture('png', 100, 100);

        $result = $this->onAMachineWith($inUse + $needed, $inUse)->process($picture);

        $this->assertSame([100, 100], [$result->width, $result->height]);

        $this->expectException(ImageRejected::class);
        $this->expectExceptionMessage('megapixels; this server has the memory for about');

        $this->onAMachineWith($inUse + $needed - 1, $inUse)->process($picture);
    }

    #[Test]
    public function what_php_has_already_used_is_not_available_to_the_picture(): void
    {
        $picture = $this->picture('png', 100, 100);

        // 40 MB of room is plenty for 0.01 megapixels, and nothing is left when the process has used it all.
        $this->assertSame(100, $this->onAMachineWith(80 * 1048576, 40 * 1048576)->process($picture)->width);

        // Even a small picture costs what every picture does (24 MB), so 10 MB of room is not enough for it.
        try {
            $this->onAMachineWith(50 * 1048576, 40 * 1048576)->process($picture);
            $this->fail('A picture was accepted with less room than every picture costs.');
        } catch (ImageRejected $exception) {
            $this->assertStringContainsString('about 0.0', $exception->getMessage());
        }

        $this->expectException(ImageRejected::class);

        $this->onAMachineWith(40 * 1048576, 40 * 1048576)->process($picture);
    }

    /**
     * @return array<string, array{0: int|null}>
     */
    public static function noLimit(): array
    {
        return ['no limit (-1)' => [-1], 'zero' => [0], 'a setting PHP does not give' => [null]];
    }

    #[Test]
    #[DataProvider('noLimit')]
    public function without_a_memory_limit_the_picture_goes_on_to_be_decoded(?int $limit): void
    {
        $this->expectException(ImageRejected::class);
        $this->expectExceptionMessage('could not be read');

        // It is not refused for its size: it is decoded, and its cut-off data is what is wrong with it.
        $this->onAMachineWith($limit)->process($this->claiming(4000, 3000));
    }

    #[Test]
    public function the_pixel_limit_still_answers_first(): void
    {
        config(['commerce.images.max_pixels' => 4_000_000]);

        $this->expectExceptionMessage('the most allowed is 4.0');

        $this->onAMachineWith(128 * 1048576)->process($this->claiming(4000, 3000));
    }

    #[Test]
    public function a_refusal_is_logged_for_the_person_who_runs_the_site(): void
    {
        Log::spy();

        try {
            $this->onAMachineWith(128 * 1048576)->process($this->claiming(4000, 3000));
        } catch (ImageRejected) {
        }

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => str_contains($message, 'memory_limit')
            && $context === ['megapixels' => 12.0, 'room_megapixels' => 8.4, 'memory_limit' => 128 * 1048576]);
    }

    #[Test]
    public function a_picture_that_fits_is_not_logged(): void
    {
        Log::spy();

        $this->onAMachineWith(128 * 1048576)->process($this->picture('png', 100, 100));

        Log::shouldNotHaveReceived('warning');
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: int}>
     */
    public static function drawn(): array
    {
        $sets = [];

        // 2000 by 2000 is just over the shop's largest size, where drawing costs the most for each pixel;
        // 3000 by 3000 is a big photo.
        foreach ([2000, 3000] as $side) {
            $sets["at the shop sizes, upright, {$side} square"] = ['process', false, $side];
            $sets["at the shop sizes, taken sideways, {$side} square"] = ['process', true, $side];
            $sets["at its own size, upright, {$side} square"] = ['kept', false, $side];
            $sets["at its own size, taken sideways, {$side} square"] = ['kept', true, $side];
        }

        return $sets;
    }

    /**
     * The guess has to be above what drawing a picture really takes, or the guard lets through what runs out of memory.
     */
    #[Test]
    #[DataProvider('drawn')]
    public function drawing_a_picture_takes_less_memory_than_the_guard_counts_on(string $how, bool $sideways, int $side): void
    {
        $this->requireExifToTurn($sideways ? 6 : 1);

        $upload = $this->photo($sideways ? 6 : 1, $side, $side);
        $processor = new ImageProcessor;

        memory_reset_peak_usage();
        $before = memory_get_usage();

        $how === 'process' ? $processor->process($upload) : $processor->webp($upload, 100_000);

        $used = memory_get_peak_usage() - $before;

        $this->assertLessThanOrEqual(ImageProcessor::memoryFor($side * $side), $used, "{$how}: ".round($used / 1048576, 1)." MB for a picture {$side} pixels square");
        // And not so much less that the figure means nothing: the picture alone is 4 bytes a pixel.
        $this->assertGreaterThan($side * $side * 4, $used);
    }

    #[Test]
    public function a_photo_taken_sideways_takes_no_more_memory_than_an_upright_one(): void
    {
        $this->requireExifToTurn();

        $measure = function (int $orientation): int {
            $upload = $this->photo($orientation, 2000, 2000);

            memory_reset_peak_usage();
            $before = memory_get_usage();
            (new ImageProcessor)->process($upload);

            return memory_get_peak_usage() - $before;
        };

        // Turned after it is made small, not before, when it is whole: the turn used to be a second full-size copy.
        $this->assertLessThan($measure(1) * 1.05, $measure(6));
    }

    #[Test]
    public function one_webp_fits_the_longest_side_and_drops_a_note(): void
    {
        $upload = $this->pngWithNote('GPS-LOCATION-51.5');

        $webp = $this->processor->webp($upload, 40);

        $info = getimagesizefromstring($webp);

        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertSame(40, max($info[0], $info[1]));
        $this->assertStringNotContainsString('GPS-LOCATION', $webp);
    }
}
