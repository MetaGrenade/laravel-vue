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
            ->assertJsonPath('short_name', 'Acme Cloud');
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
