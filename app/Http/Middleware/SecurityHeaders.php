<?php

namespace App\Http\Middleware;

use App\Payments\Tebex\TebexCheckoutMode;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds security headers to every answer, and a nonce-based Content Security Policy
 * to the site's own pages. Configure in config/security.php.
 *
 * It is the outermost global middleware (bootstrap/app.php), so an answer that never
 * reaches a route's own middleware gets them too.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $cspEnabled = (bool) config('security.csp.enabled');

        if ($cspEnabled) {
            // Must happen before the view renders so @vite, @routes and inline
            // scripts can read the nonce.
            Vite::useCspNonce();
        }

        $response = $next($request);

        foreach ((array) config('security.headers', []) as $header => $value) {
            if ($value !== null && ! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        if (config('security.hsts.enabled') && $request->isSecure()) {
            $value = 'max-age='.(int) config('security.hsts.max_age');

            if (config('security.hsts.include_subdomains')) {
                $value .= '; includeSubDomains';
            }

            $response->headers->set('Strict-Transport-Security', $value);
        }

        if ($cspEnabled && $this->isHtml($response) && $this->takesThePolicy($request)) {
            $header = config('security.csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';

            $response->headers->set($header, $this->policy());
        }

        return $response;
    }

    private function policy(): string
    {
        $directives = (array) config('security.csp.directives', []);
        $nonce = Vite::cspNonce();

        foreach ($this->runtimeSources() as $directive => $sources) {
            $directives[$directive] = [...($directives[$directive] ?? []), ...$sources];
        }

        $policy = collect($directives)
            ->map(function (array $sources, string $directive) use ($nonce) {
                $sources = array_map(
                    fn (string $source) => $source === '{nonce}' ? "'nonce-{$nonce}'" : $source,
                    array_unique($sources),
                );

                return trim($directive.' '.implode(' ', $sources));
            })
            ->values();

        if ($reportUri = config('security.csp.report_uri')) {
            $policy->push("report-uri {$reportUri}");
        }

        return $policy->implode('; ');
    }

    /**
     * Sources that depend on the environment: the Vite dev server during
     * development, a self-hosted broadcasting server (Reverb, Soketi) and, when the checkout is embedded, Tebex's.
     *
     * @return array<string, list<string>>
     */
    private function runtimeSources(): array
    {
        $sources = [];

        if (Vite::isRunningHot()) {
            $hot = rtrim((string) file_get_contents(Vite::hotFile()));
            $ws = preg_replace('#^http#', 'ws', $hot);

            $sources['script-src'][] = $hot;
            $sources['style-src'][] = $hot;
            $sources['font-src'][] = $hot;
            $sources['img-src'][] = $hot;
            $sources['connect-src'][] = $hot;
            $sources['connect-src'][] = $ws;
        }

        if ($host = config('broadcasting.connections.pusher.options.host')) {
            $port = config('broadcasting.connections.pusher.options.port');
            $authority = $host.($port ? ":{$port}" : '');

            $sources['connect-src'][] = "wss://{$authority}";
            $sources['connect-src'][] = "ws://{$authority}";
            $sources['connect-src'][] = "https://{$authority}";
        }

        // Tebex.js puts Tebex's checkout in a frame on the shop's own page. A shop that sends buyers to Tebex's page
        // instead lets no Tebex frame in.
        if (TebexCheckoutMode::current()->isEmbedded()) {
            $sources['frame-src'] = array_values(array_filter((array) config('tebex.checkout.frame_sources', []), is_string(...)));
        }

        return $sources;
    }

    private function isHtml(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }

    /**
     * Whether the policy was written for this page: one of the site's own, or the error page of an address no route has.
     *
     * A route outside the `web` and `api` groups is Laravel's or a package's, with a page that loads what it likes: the
     * health check takes its styles from other hosts, and the script of Cashier's page, where a customer confirms a
     * payment, has no nonce. The policy would break both.
     */
    private function takesThePolicy(Request $request): bool
    {
        $route = $request->route();

        return ! $route instanceof Route || array_intersect(['web', 'api'], $route->middleware()) !== [];
    }
}
