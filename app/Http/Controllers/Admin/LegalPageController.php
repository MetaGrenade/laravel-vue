<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Legal\LegalPages;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The three legal pages (docs/legal-pages.md). Looking needs system.acp.view; saving needs system.acp.edit.
 */
class LegalPageController extends Controller
{
    public function edit(LegalPages $legal): Response
    {
        return Inertia::render('acp/LegalPages', [
            'pages' => $legal->fields(),
        ]);
    }

    public function update(Request $request, LegalPages $legal): RedirectResponse
    {
        $validated = $request->validate([
            'terms' => ['present', 'nullable', 'string', 'max:100000'],
            'privacy' => ['present', 'nullable', 'string', 'max:100000'],
            'cookies' => ['present', 'nullable', 'string', 'max:100000'],
        ]);

        $legal->save([
            'terms' => is_string($validated['terms'] ?? null) ? $validated['terms'] : null,
            'privacy' => is_string($validated['privacy'] ?? null) ? $validated['privacy'] : null,
            'cookies' => is_string($validated['cookies'] ?? null) ? $validated['cookies'] : null,
        ]);

        return back()->with('success', __('legal.admin.saved'));
    }
}
