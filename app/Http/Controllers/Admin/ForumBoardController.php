<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ManagesForumStructure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderForumBoardRequest;
use App\Http\Requests\Admin\StoreForumBoardRequest;
use App\Http\Requests\Admin\UpdateForumBoardRequest;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ForumBoardController extends Controller
{
    use ManagesForumStructure;

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('forums.acp.create'), 403);

        $categories = ForumCategory::query()
            ->orderBy('position')
            ->get(['id', 'title']);

        $defaultCategoryId = $request->has('category') ? (int) $request->get('category') : null;

        return inertia('acp/ForumBoardCreate', [
            'categories' => $categories->map(fn (ForumCategory $category) => [
                'id' => $category->id,
                'title' => $category->title,
            ])->values()->all(),
            'defaultCategoryId' => $defaultCategoryId,
        ]);
    }

    public function store(StoreForumBoardRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $categoryId = (int) $validated['forum_category_id'];

        ForumBoard::create([
            'forum_category_id' => $categoryId,
            'title' => $validated['title'],
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['title'], ForumBoard::class),
            'description' => $validated['description'] ?? null,
            'position' => (ForumBoard::where('forum_category_id', $categoryId)->max('position') ?? 0) + 1,
        ]);

        return redirect()
            ->route('acp.forums.index')
            ->with('success', 'Forum board created successfully.');
    }

    public function edit(Request $request, ForumBoard $board): Response
    {
        abort_unless($request->user()?->can('forums.acp.edit'), 403);

        $categories = ForumCategory::query()
            ->orderBy('position')
            ->get(['id', 'title']);

        return inertia('acp/ForumBoardEdit', [
            'board' => [
                'id' => $board->id,
                'title' => $board->title,
                'slug' => $board->slug,
                'description' => $board->description,
                'forum_category_id' => $board->forum_category_id,
            ],
            'categories' => $categories->map(fn (ForumCategory $category) => [
                'id' => $category->id,
                'title' => $category->title,
            ])->values()->all(),
        ]);
    }

    public function update(UpdateForumBoardRequest $request, ForumBoard $board): RedirectResponse
    {
        $validated = $request->validated();

        $previousCategoryId = $board->forum_category_id;
        $targetCategoryId = (int) $validated['forum_category_id'];
        $newPosition = $board->position;

        if ($targetCategoryId !== $previousCategoryId) {
            $newPosition = (ForumBoard::where('forum_category_id', $targetCategoryId)->max('position') ?? 0) + 1;
        }

        $board->forceFill([
            'forum_category_id' => $targetCategoryId,
            'title' => $validated['title'],
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['title'], ForumBoard::class, $board->id),
            'description' => $validated['description'] ?? null,
            'position' => $newPosition,
        ])->save();

        if ($targetCategoryId !== $previousCategoryId) {
            $this->resequenceBoards($previousCategoryId);
        }

        return redirect()
            ->route('acp.forums.index')
            ->with('success', 'Forum board updated successfully.');
    }

    public function destroy(Request $request, ForumBoard $board): RedirectResponse
    {
        abort_unless($request->user()?->can('forums.acp.delete'), 403);

        $categoryId = $board->forum_category_id;
        $board->delete();
        $this->resequenceBoards($categoryId);

        return redirect()
            ->route('acp.forums.index')
            ->with('success', 'Forum board deleted successfully.');
    }

    public function reorder(ReorderForumBoardRequest $request, ForumBoard $board): RedirectResponse
    {
        $direction = $request->validated()['direction'];

        $neighbor = ForumBoard::query()
            ->where('forum_category_id', $board->forum_category_id)
            ->when(
                $direction === 'up',
                fn ($query) => $query->where('position', '<', $board->position)->orderByDesc('position'),
                fn ($query) => $query->where('position', '>', $board->position)->orderBy('position')
            )
            ->first();

        if ($neighbor) {
            $this->swapPositions($board, $neighbor);
        }

        return redirect()
            ->back()
            ->with('success', 'Forum boards reordered successfully.');
    }
}
