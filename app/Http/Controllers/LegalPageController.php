<?php

namespace App\Http\Controllers;

use App\Support\Legal\LegalPages;
use App\Support\Localization\DateFormatter;
use App\Support\Seo\Seo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One published legal page (docs/legal-pages.md).
 */
class LegalPageController extends Controller
{
    public function show(Request $request, string $page, LegalPages $legal, Seo $seo): Response
    {
        $body = $legal->body($page);

        if ($body === null || ! array_key_exists($page, LegalPages::PAGES)) {
            abort(404);
        }

        $title = (string) __(LegalPages::PAGES[$page]);

        $seo->title($title)
            ->description($legal->description($page))
            ->canonical(route('legal.show', ['page' => $page]))
            ->schema([
                '@type' => 'WebPage',
                'name' => $title,
                'url' => route('legal.show', ['page' => $page]),
            ]);

        return Inertia::render('Legal', [
            'slug' => $page,
            'title' => $title,
            'body' => $body,
            'updated_at' => DateFormatter::for($request->user())->date($legal->updatedAt($page)),
        ]);
    }
}
