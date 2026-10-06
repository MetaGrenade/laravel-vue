<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds security headers (and a nonce-based Content Security Policy) to web
 * responses. Configure in config/security.php.
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

        if ($cspEnabled && $this->isHtml($response)) {
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
     * development and a self-hosted broadcasting server (Reverb, Soketi).
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

        return $sources;
    }

    private function isHtml(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
