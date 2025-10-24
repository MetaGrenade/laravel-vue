<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Support\WebsiteSections;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteSectionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_blog_routes_return_not_found_when_section_disabled(): void
    {
        $sections = WebsiteSections::defaults();
        $sections[WebsiteSections::BLOG] = false;
        SystemSetting::set('website_sections', $sections);

        $this->get('/blogs')->assertNotFound();
        $this->get('/blogs/feed')->assertNotFound();
    }

    public function test_forum_routes_return_not_found_when_section_disabled(): void
    {
        $sections = WebsiteSections::defaults();
        $sections[WebsiteSections::FORUM] = false;
        SystemSetting::set('website_sections', $sections);

        $this->get('/forum')->assertNotFound();
    }

    public function test_support_routes_return_not_found_when_section_disabled(): void
    {
        $sections = WebsiteSections::defaults();
        $sections[WebsiteSections::SUPPORT] = false;
        SystemSetting::set('website_sections', $sections);

        $this->get('/support')->assertNotFound();
    }
}
