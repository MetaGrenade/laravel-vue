<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_html_responses_include_security_headers_and_a_nonce_based_csp(): void
    {
        $response = $this->get(route('home'))->assertOk();

        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-([A-Za-z0-9]+)'/", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);

        preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $matches);

        // Every inline/module script rendered by the root view carries the nonce.
        $response->assertSee('nonce="'.$matches[1].'"', false);
    }

    public function test_csp_can_run_in_report_only_mode(): void
    {
        config(['security.csp.report_only' => true]);

        $response = $this->get(route('home'));

        $this->assertNull($response->headers->get('Content-Security-Policy'));
        $this->assertNotNull($response->headers->get('Content-Security-Policy-Report-Only'));
    }

    public function test_hsts_is_only_sent_over_https(): void
    {
        config(['security.hsts.enabled' => true]);

        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_json_responses_do_not_receive_a_csp(): void
    {
        $this->get('/sitemap.xml')->assertHeaderMissing('Content-Security-Policy');
    }
}
