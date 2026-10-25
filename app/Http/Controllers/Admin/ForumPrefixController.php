<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ManagesForumStructure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreForumPrefixRequest;
use App\Http\Requests\Admin\UpdateForumPrefixRequest;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPrefix;
use App\Support\Forum\ThreadPrefixes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

/**
 * The labels a board can offer on a new thread (docs/forum-prefixes.md).
 */
class ForumPrefixController extends Controller
{
    use ManagesForumStructure;

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('forums.acp.view'), 403);

        $prefixes = ForumPrefix::query()
            ->with(['boards:id,title'])
            ->withCount('threads')
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        return inertia('acp/ForumPrefixes', [
            'prefixes' => $prefixes->map(fn (ForumPrefix $prefix) => [
                'id' => $prefix->id,
                'name' => $prefix->name,
                'slug' => $prefix->slug,
                'color' => $prefix->color,
                'position' => $prefix->position,
                'thread_count' => $prefix->threads_count,
                'boards' => $prefix->boards->map(fn (ForumBoard $board): array => [
                    'id' => $board->id,
                    'title' => $board->title,
                ])->values()->all(),
            ])->values()->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('forums.acp.create'), 403);

        return inertia('acp/ForumPrefixForm', [
            'prefix' => null,
            'colors' => ThreadPrefixes::COLORS,
            'categories' => $this->categories(),
        ]);
    }

    public function store(StoreForumPrefixRequest $request, ThreadPrefixes $prefixes): RedirectResponse
    {
        $validated = $request->validated();

        $prefix = ForumPrefix::create([
            'name' => trim($validated['name']),
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['name'], ForumPrefix::class),
            'color' => $validated['color'],
            'position' => (ForumPrefix::query()->max('position') ?? 0) + 1,
        ]);

        $prefixes->syncBoards($prefix, $this->boardIds($validated['boards'] ?? []));

        return redirect()
            ->route('acp.forums.prefixes.index')
            ->with('success', 'Prefix created.');
    }

    public function edit(Request $request, ForumPrefix $prefix): Response
    {
        abort_unless($request->user()?->can('forums.acp.edit'), 403);

        $prefix->load('boards:id');

        return inertia('acp/ForumPrefixForm', [
            'prefix' => [
                'id' => $prefix->id,
                'name' => $prefix->name,
                'slug' => $prefix->slug,
                'color' => $prefix->color,
                'position' => $prefix->position,
                'board_ids' => $prefix->boards->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ],
            'colors' => ThreadPrefixes::COLORS,
            'categories' => $this->categories(),
        ]);
    }

    public function update(UpdateForumPrefixRequest $request, ForumPrefix $prefix, ThreadPrefixes $prefixes): RedirectResponse
    {
        $validated = $request->validated();

        $prefix->forceFill([
            'name' => trim($validated['name']),
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['name'], ForumPrefix::class, $prefix->id),
            'color' => $validated['color'],
            'position' => (int) $validated['position'],
        ])->save();

        $prefixes->syncBoards($prefix, $this->boardIds($validated['boards'] ?? []));

        return redirect()
            ->route('acp.forums.prefixes.index')
            ->with('success', 'Prefix updated.');
    }

    public function destroy(Request $request, ForumPrefix $prefix): RedirectResponse
    {
        abort_unless($request->user()?->can('forums.acp.delete'), 403);

        $prefix->delete();

        return redirect()
            ->route('acp.forums.prefixes.index')
            ->with('success', 'Prefix deleted.');
    }

    /**
     * @return list<array{id: int, title: string, boards: list<array{id: int, title: string}>}>
     */
    private function categories(): array
    {
        $categories = [];

        foreach (ForumCategory::query()->orderBy('position')->orderBy('title')->get() as $category) {
            $boards = [];

            foreach (ForumBoard::query()->where('forum_category_id', $category->id)->orderBy('position')->orderBy('title')->get() as $board) {
                $boards[] = [
                    'id' => $board->id,
                    'title' => $board->title,
                ];
            }

            $categories[] = [
                'id' => $category->id,
                'title' => $category->title,
                'boards' => $boards,
            ];
        }

        return $categories;
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function boardIds(array $ids): array
    {
        $boardIds = [];

        foreach ($ids as $id) {
            $boardIds[] = (int) $id;
        }

        return $boardIds;
    }
}
