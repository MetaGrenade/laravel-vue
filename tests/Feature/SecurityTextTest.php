<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_txt_names_the_contact_the_policy_and_an_expiry_within_a_year(): void
    {
        $this->travelTo(Carbon::parse('2026-10-28 12:00:00', 'UTC'));

        $this->get(route('security.txt'))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->assertSee('Contact: https://github.com/MetaGrenade/laravel-vue/security/advisories/new', false)
            ->assertSee('Expires: 2027-10-27T00:00:00Z', false)
            ->assertSee('Preferred-Languages: en', false)
            ->assertSee('Canonical: '.route('security.txt'), false)
            ->assertSee('Policy: https://github.com/MetaGrenade/laravel-vue/security/policy', false);
    }

    public function test_the_contact_and_the_policy_can_be_changed_and_a_broken_line_is_left_out(): void
    {
        config([
            'security.contact.uri' => 'https://example.test/report',
            'security.contact.policy' => "https://example.test/policy\nContact: hidden",
        ]);

        $response = $this->get(route('security.txt'))->assertOk();

        $response->assertSee('Contact: https://example.test/report', false);
        $response->assertDontSee('hidden', false);
        $response->assertDontSee('Policy:', false);
    }

    public function test_the_contact_stays_available_while_the_site_is_in_maintenance(): void
    {
        SystemSetting::set('maintenance_mode', true);

        $this->get(route('home'))->assertStatus(503);

        $this->get(route('security.txt'))
            ->assertOk()
            ->assertSee('Contact: https://github.com/MetaGrenade/laravel-vue/security/advisories/new', false);
    }
}
