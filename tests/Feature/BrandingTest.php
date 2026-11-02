<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_web_manifest_is_named_after_the_configured_site(): void
    {
        config(['seo.site_name' => 'Acme Cloud']);

        $this->get(route('webmanifest'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json')
            ->assertJsonPath('name', 'Acme Cloud')
            ->assertJsonPath('short_name', 'Acme Cloud')
            ->assertJsonPath('icons.0.src', '/android-chrome-192x192.png')
            ->assertJsonPath('icons.1.src', '/android-chrome-512x512.png');
    }

    #[Test]
    public function the_brand_icons_are_published(): void
    {
        foreach ([
            public_path('favicon.ico'),
            public_path('favicon-16x16.png'),
            public_path('favicon-32x32.png'),
            public_path('apple-touch-icon.png'),
            public_path('android-chrome-192x192.png'),
            public_path('android-chrome-512x512.png'),
            public_path('brand/logo-dark.png'),
            public_path('brand/logo-light.png'),
            public_path('brand/mark.png'),
        ] as $path) {
            $this->assertFileExists($path);
        }
    }

    #[Test]
    public function every_brand_file_has_the_size_it_is_used_at(): void
    {
        foreach ([
            'brand/logo-light.png' => [1024, 341],
            'brand/logo-dark.png' => [1024, 341],
            'brand/mark.png' => [192, 192],
            'apple-touch-icon.png' => [180, 180],
            'android-chrome-192x192.png' => [192, 192],
            'android-chrome-512x512.png' => [512, 512],
            'favicon-32x32.png' => [32, 32],
            'favicon-16x16.png' => [16, 16],
        ] as $file => [$width, $height]) {
            $size = getimagesize(public_path($file));

            $this->assertSame([$width, $height, 'image/png'], [$size[0], $size[1], $size['mime']], "{$file} is not a {$width} x {$height} PNG.");
        }
    }

    #[Test]
    public function the_icon_file_holds_the_three_sizes_browsers_ask_for(): void
    {
        $ico = (string) file_get_contents(public_path('favicon.ico'));
        $header = unpack('vreserved/vtype/vcount', $ico);

        $this->assertSame(1, $header['type'], 'favicon.ico is not an icon file.');

        $sizes = [];
        for ($i = 0; $i < $header['count']; $i++) {
            $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vlength/Voffset', substr($ico, 6 + 16 * $i, 16));
            $picture = getimagesizefromstring(substr($ico, $entry['offset'], $entry['length']));

            // The entry's own size must be the size of the picture in it, or a browser draws the wrong one.
            $this->assertSame([$entry['width'], $entry['height']], [$picture[0], $picture[1]]);
            $sizes[] = $entry['width'];
        }

        $this->assertSame([16, 32, 48], $sizes);
    }

    #[Test]
    public function the_logos_and_icons_are_transparent_so_they_sit_on_any_page(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('The GD extension is needed to read the pixels.');
        }

        foreach (['brand/logo-light.png', 'brand/logo-dark.png', 'brand/mark.png', 'apple-touch-icon.png', 'android-chrome-192x192.png', 'android-chrome-512x512.png', 'favicon-32x32.png', 'favicon-16x16.png'] as $file) {
            $image = imagecreatefrompng(public_path($file));

            // The corners are empty in every one of them; a solid background would show as a box on the other colour scheme.
            foreach ([[0, 0], [imagesx($image) - 1, 0], [0, imagesy($image) - 1], [imagesx($image) - 1, imagesy($image) - 1]] as [$x, $y]) {
                $this->assertSame(127, (imagecolorat($image, $x, $y) >> 24) & 127, "{$file} is not transparent at {$x},{$y}.");
            }
        }
    }

    #[Test]
    public function the_light_logo_has_dark_lettering_and_the_dark_logo_has_light_lettering(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('The GD extension is needed to read the pixels.');
        }

        // The two files are used by the colour scheme (AppLogo.vue), so a swap would show dark lettering on a dark page.
        $this->assertLessThan(0.25, $this->letteringBrightness('brand/logo-light.png'), 'The logo for light pages has light lettering.');
        $this->assertGreaterThan(0.75, $this->letteringBrightness('brand/logo-dark.png'), 'The logo for dark pages has dark lettering.');
    }

    #[Test]
    public function the_home_page_title_uses_the_configured_site_name(): void
    {
        config([
            'seo.site_name' => 'Acme Cloud',
            'seo.home.title' => 'Build faster',
        ]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<title data-inertia="">Build faster - Acme Cloud</title>', false)
            ->assertDontSee('Laravel Vue Starter Kit');
    }

    #[Test]
    public function the_shared_app_name_prop_comes_from_configuration(): void
    {
        config(['app.name' => 'Acme Cloud']);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Acme Cloud');
    }

    /**
     * How light the word "Meta" is, 0 (black) to 1 (white): the mean of its solid pixels, in the part of a wordmark that it fills (the
     * mark is to the left of it and "Forger", which is coloured in both logos, to the right).
     */
    private function letteringBrightness(string $file): float
    {
        $image = imagecreatefrompng(public_path($file));
        $sum = 0.0;
        $count = 0;

        for ($y = 0; $y < imagesy($image); $y++) {
            for ($x = 265; $x < 560; $x++) {
                $color = imagecolorat($image, $x, $y);

                if ((($color >> 24) & 127) === 0) {
                    $sum += (0.2126 * (($color >> 16) & 255) + 0.7152 * (($color >> 8) & 255) + 0.0722 * ($color & 255)) / 255;
                    $count++;
                }
            }
        }

        $this->assertGreaterThan(1000, $count, "No lettering found in {$file}.");

        return $sum / $count;
    }
}
