<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_are_indexable_when_indexing_is_enabled(): void
    {
        config(['seo.indexing' => true]);

        $this->get(route('home'))
            ->assertOk()
            ->assertSee('<meta name="robots" content="index, follow, max-image-preview:large"', false)
            ->assertSee('<link rel="canonical" href="'.e(route('home')).'"', false)
            ->assertSee('"@type":"WebSite"', false);
    }

    public function test_private_pages_are_never_indexed(): void
    {
        config(['seo.indexing' => true]);

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow"', false);
    }

    public function test_indexing_can_be_disabled_site_wide(): void
    {
        config(['seo.indexing' => false]);

        $this->get(route('home'))->assertSee('<meta name="robots" content="noindex, nofollow"', false);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee("Disallow: /\n", false)
            ->assertDontSee('Sitemap:');
    }

    public function test_robots_txt_blocks_private_areas_and_links_the_sitemap(): void
    {
        config(['seo.indexing' => true]);

        $this->get('/robots.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Disallow: /acp')
            ->assertSee('Sitemap: '.route('sitemap'));
    }

    public function test_sitemap_lists_published_content_only(): void
    {
        $published = Blog::factory()->published()->create(['slug' => 'visible-post']);
        Blog::factory()->create(['slug' => 'draft-post', 'status' => 'draft']);

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertSee('<loc>'.e(route('blogs.view', ['slug' => $published->slug])).'</loc>', false)
            ->assertDontSee('draft-post');
    }

    public function test_page_meta_escapes_user_content(): void
    {
        Blog::factory()->published()->create([
            'title' => '"><script>alert(1)</script>',
            'slug' => 'escaped-title',
        ]);

        $this->get(route('blogs.view', ['slug' => 'escaped-title']))
            ->assertOk()
            ->assertDontSee('"><script>alert(1)</script>', false);
    }

    public function test_guests_do_not_receive_admin_routes(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertDontSee('acp.dashboard', false);
    }

    public function test_staff_receive_admin_routes(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('acp.dashboard', false);
    }
}
