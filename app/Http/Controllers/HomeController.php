<?php

namespace App\Http\Controllers;

use App\Support\Seo\Seo;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Seo $seo): Response
    {
        $siteName = (string) config('seo.site_name');

        $seo->title((string) config('seo.home.title'))
            ->description((string) config('seo.home.description'))
            ->canonical(route('home'))
            ->schema([
                '@type' => 'WebSite',
                'name' => $siteName,
                'url' => route('home'),
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => route('search.results').'?q={search_term_string}',
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ])
            ->schema(array_filter([
                '@type' => 'Organization',
                'name' => $siteName,
                'url' => route('home'),
                'logo' => url('/favicon.svg'),
            ]));

        return Inertia::render('Welcome');
    }
}
