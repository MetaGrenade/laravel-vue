<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
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
        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), browsing-topics=()');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');

        $csp = $response->headers->get('Content-Security-Policy');

        $this->assertNotNull($csp);
        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-([A-Za-z0-9]+)'/", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("base-uri 'self'", $csp);
        // Vue sets inline styles. The whole source list is compared, so a host or a wildcard added later fails.
        $this->assertSame($this->allowedStyleSources(), $this->directiveSources($csp, 'style-src'));

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
        config([
            'security.hsts.enabled' => true,
            'security.hsts.include_subdomains' => false,
        ]);

        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');

        config(['security.hsts.include_subdomains' => true]);

        $this->get('https://localhost/')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
    }

    public function test_responses_that_are_not_html_keep_the_other_headers_and_omit_the_csp(): void
    {
        foreach ([route('sitemap'), route('webmanifest'), route('security.txt')] as $url) {
            $response = $this->get($url)->assertOk();

            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
            $response->assertHeaderMissing('Content-Security-Policy');
            $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
        }
    }

    public function test_an_address_no_route_has_carries_the_headers_and_the_policy(): void
    {
        $this->assertGuardedPage($this->get('/no/such/address/here')->assertNotFound());
        $this->assertGuardedPage($this->post('/no/such/address/here')->assertNotFound());
    }

    public function test_a_method_an_address_does_not_take_carries_them(): void
    {
        $this->assertGuardedPage($this->delete(route('home'))->assertStatus(405));
    }

    public function test_a_record_the_route_does_not_find_carries_them(): void
    {
        $this->assertGuardedPage($this->get('/forum/no-such-board')->assertNotFound());
    }

    public function test_a_visitor_sent_to_sign_in_gets_them(): void
    {
        $this->assertGuardedPage($this->get(route('dashboard'))->assertRedirect(route('login')));
    }

    public function test_a_form_sent_without_its_token_carries_them(): void
    {
        // The token is not asked for while unit tests run.
        $this->app->detectEnvironment(fn () => 'production');

        $this->assertGuardedPage($this->post(route('login'))->assertStatus(419));
    }

    public function test_a_request_over_its_limit_carries_them(): void
    {
        Route::middleware(['web', 'throttle:1,1'])->get('/_headers/limited', fn () => 'once a minute');

        $this->get('/_headers/limited')->assertOk();

        $this->assertGuardedPage($this->get('/_headers/limited')->assertTooManyRequests());
    }

    public function test_the_page_shown_while_the_site_is_down_carries_them(): void
    {
        // Kept in memory: the file driver would put the site in this folder into maintenance mode.
        config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
        $this->app->maintenanceMode()->activate(['status' => 503]);

        $this->assertGuardedPage($this->get(route('home'))->assertStatus(503));
    }

    public function test_a_form_too_big_to_be_read_is_refused_with_them(): void
    {
        $response = $this->call('POST', route('login'), server: ['CONTENT_LENGTH' => (string) PHP_INT_MAX]);

        $this->assertGuardedPage($response->assertStatus(413));
    }

    public function test_an_error_over_https_asks_for_https_from_then_on(): void
    {
        config(['security.hsts.enabled' => true, 'security.hsts.include_subdomains' => false]);

        $this->get('https://localhost/no/such/address/here')
            ->assertNotFound()
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_the_policy_of_an_error_page_is_only_reported_in_report_only_mode(): void
    {
        config(['security.csp.report_only' => true]);

        $this->get('/no/such/address/here')
            ->assertNotFound()
            ->assertHeaderMissing('Content-Security-Policy')
            ->assertHeader('Content-Security-Policy-Report-Only');
    }

    public function test_answers_of_the_api_carry_the_headers_and_no_policy(): void
    {
        $this->assertHeadersWithoutPolicy($this->getJson('/api/v1/profile')->assertUnauthorized());
        $this->assertHeadersWithoutPolicy($this->getJson('/api/no-such-thing')->assertNotFound());
    }

    public function test_a_browser_that_opens_an_address_of_the_api_gets_a_guarded_page(): void
    {
        // It does not ask for data, so it is sent to sign in, as on the site's own pages.
        $this->assertGuardedPage($this->get('/api/v1/profile')->assertRedirect(route('login')));
    }

    public function test_the_health_check_keeps_its_page_working(): void
    {
        // Laravel's page: it loads its styles from other hosts, which the policy would refuse.
        $response = $this->get('/up')->assertOk()->assertSee('https://fonts.bunny.net', false);

        $this->assertHeadersWithoutPolicy($response);
    }

    public function test_the_page_where_a_payment_is_confirmed_keeps_working(): void
    {
        // Cashier's page, where a customer's bank asks them to confirm: its script is written into the page, with no nonce.
        $this->app->bind(StripeClient::class, fn () => new class
        {
            public object $paymentIntents;

            public function __construct()
            {
                $this->paymentIntents = new class
                {
                    public function retrieve(string $id): PaymentIntent
                    {
                        return PaymentIntent::constructFrom([
                            'id' => $id, 'amount' => 500, 'currency' => 'usd', 'status' => 'requires_action',
                            'client_secret' => $id.'_secret', 'payment_method_types' => ['card'], 'payment_method' => null, 'customer' => null,
                        ]);
                    }
                };
            }
        });

        $response = $this->get(route('cashier.payment', 'pi_headers'))->assertOk()->assertSee('<script>', false);

        $this->assertHeadersWithoutPolicy($response);
    }

    public function test_only_laravels_own_routes_and_cashiers_payment_page_go_without_the_policy(): void
    {
        $outside = collect(Route::getRoutes()->getRoutes())
            ->reject(fn ($route) => array_intersect(['web', 'api'], $route->middleware()) !== [])
            ->map(fn ($route) => $route->uri())
            ->unique()
            ->sort()
            ->values()
            ->all();

        // A route here is answered without the content security policy: a page of the site's own belongs in the `web` group.
        $this->assertSame(['billing/payment/{id}', 'storage/{path}', 'up'], $outside);
    }

    private function assertGuardedPage(TestResponse $response): void
    {
        $this->assertConfiguredHeaders($response);

        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertMatchesRegularExpression("/script-src [^;]*'nonce-[A-Za-z0-9]+'/", $policy);
    }

    private function assertHeadersWithoutPolicy(TestResponse $response): void
    {
        $this->assertConfiguredHeaders($response);

        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    private function assertConfiguredHeaders(TestResponse $response): void
    {
        $configured = (array) config('security.headers');

        $this->assertNotEmpty($configured);

        foreach ($configured as $header => $value) {
            $response->assertHeader($header, $value);
        }
    }

    /**
     * `'self'` and `'unsafe-inline'`, plus the Vite dev server only while it is actually running.
     *
     * @return list<string>
     */
    private function allowedStyleSources(): array
    {
        $sources = ["'self'", "'unsafe-inline'"];

        if (Vite::isRunningHot()) {
            $sources[] = rtrim((string) file_get_contents(Vite::hotFile()));
        }

        return $sources;
    }

    /**
     * Every source on one directive, in the order the policy sends them.
     *
     * @return list<string>
     */
    private function directiveSources(string $policy, string $directive): array
    {
        $found = [];

        foreach (explode(';', $policy) as $part) {
            $tokens = preg_split('/\s+/', trim($part), -1, PREG_SPLIT_NO_EMPTY);

            if (! is_array($tokens) || ($tokens[0] ?? null) !== $directive) {
                continue;
            }

            $found[] = array_values(array_slice($tokens, 1));
        }

        $this->assertCount(1, $found, "The content security policy should have one {$directive} directive.");

        return $found[0];
    }
}
