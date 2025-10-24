<?php

namespace App\Http\Middleware;

use App\Support\WebsiteSections;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EnsureWebsiteSectionIsEnabled
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, string $section)
    {
        if (! WebsiteSections::isEnabled($section)) {
            throw new NotFoundHttpException();
        }

        return $next($request);
    }
}
