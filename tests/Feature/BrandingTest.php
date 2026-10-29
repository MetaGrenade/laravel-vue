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
}
