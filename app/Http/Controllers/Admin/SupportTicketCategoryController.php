<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SupportTicketCategoryRequest;
use App\Models\SupportTicketCategory;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class SupportTicketCategoryController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $formatter = DateFormatter::for($request->user());

        $categories = SupportTicketCategory::query()
            ->withCount('tickets')
            ->orderBy('name')
            ->get()
            ->map(fn (SupportTicketCategory $category) => [
                'id' => $category->id,
                'name' => $category->name,
                'tickets_count' => $category->tickets_count ?? 0,
                'created_at' => $formatter->iso($category->created_at),
                'updated_at' => $formatter->iso($category->updated_at),
            ])
            ->values()
            ->all();

        if ($request->wantsJson()) {
            return response()->json(['categories' => $categories]);
        }

        return inertia('acp/SupportTicketCategories', [
            'categories' => $categories,
        ]);
    }

    public function create(): Response
    {
        return inertia('acp/SupportTicketCategoryCreate');
    }

    public function store(SupportTicketCategoryRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        SupportTicketCategory::create([
            'name' => $validated['name'],
        ]);

        return redirect()
            ->route('acp.support.ticket-categories.index')
            ->with('success', 'Category created successfully.');
    }

    public function edit(SupportTicketCategory $category): Response
    {
        $category->loadCount('tickets');

        $formatter = DateFormatter::for(request()->user());

        return inertia('acp/SupportTicketCategoryEdit', [
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'tickets_count' => $category->tickets_count ?? 0,
                'created_at' => $formatter->iso($category->created_at),
                'updated_at' => $formatter->iso($category->updated_at),
            ],
        ]);
    }

    public function update(SupportTicketCategoryRequest $request, SupportTicketCategory $category): RedirectResponse
    {
        $validated = $request->validated();

        $category->forceFill([
            'name' => $validated['name'],
        ])->save();

        return redirect()
            ->route('acp.support.ticket-categories.index')
            ->with('success', 'Category updated successfully.');
    }

    public function destroy(SupportTicketCategory $category): RedirectResponse
    {
        $category->delete();

        return redirect()
            ->route('acp.support.ticket-categories.index')
            ->with('success', 'Category deleted successfully.');
    }
}
