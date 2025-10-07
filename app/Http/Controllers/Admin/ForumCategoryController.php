<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ManagesForumStructure;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReorderForumCategoryRequest;
use App\Http\Requests\Admin\StoreForumCategoryRequest;
use App\Http\Requests\Admin\UpdateForumCategoryRequest;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;

class ForumCategoryController extends Controller
{
    use ManagesForumStructure;

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('forums.acp.view'), 403);

        $formatter = DateFormatter::for($request->user());

        $categories = ForumCategory::query()
            ->with(['boards' => function ($query) {
                $query->withCount(['threads', 'posts'])
                    ->orderBy('position')
                    ->with([
                        'latestThread' => function ($threadQuery) {
                            $threadQuery->with(['author:id,nickname', 'lastPostAuthor:id,nickname']);
                        },
                    ]);
            }])
            ->orderBy('position')
            ->get();

        $categoryItems = $categories->map(function (ForumCategory $category) use ($formatter) {
            return [
                'id' => $category->id,
                'title' => $category->title,
                'slug' => $category->slug,
                'description' => $category->description,
                'position' => $category->position,
                'boards' => $category->boards->map(function (ForumBoard $board) use ($formatter) {
                    $latestThread = $board->latestThread;
                    $latestAuthor = $latestThread?->lastPostAuthor ?? $latestThread?->author;
                    $latestTimestamp = $latestThread?->last_posted_at ?? $latestThread?->created_at;

                    return [
                        'id' => $board->id,
                        'title' => $board->title,
                        'slug' => $board->slug,
                        'description' => $board->description,
                        'position' => $board->position,
                        'thread_count' => $board->threads_count ?? 0,
                        'post_count' => $board->posts_count ?? 0,
                        'latest_post' => $latestThread ? [
                            'title' => $latestThread->title,
                            'author' => $latestAuthor ? [
                                'id' => $latestAuthor->id,
                                'nickname' => $latestAuthor->nickname,
                            ] : null,
                            'posted_at' => $formatter->iso($latestTimestamp),
                        ] : null,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        $stats = [
            ['title' => 'Total Categories', 'value' => ForumCategory::count()],
            ['title' => 'Total Boards', 'value' => ForumBoard::count()],
            ['title' => 'Total Threads', 'value' => ForumThread::count()],
            ['title' => 'Total Posts', 'value' => ForumPost::count()],
        ];

        return inertia('acp/Forums', [
            'stats' => $stats,
            'categories' => $categoryItems,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('forums.acp.create'), 403);

        return inertia('acp/ForumCategoryCreate');
    }

    public function store(StoreForumCategoryRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        ForumCategory::create([
            'title' => $validated['title'],
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['title'], ForumCategory::class),
            'description' => $validated['description'] ?? null,
            'position' => (ForumCategory::max('position') ?? 0) + 1,
        ]);

        return redirect()
            ->route('acp.forums.index')
            ->with('success', 'Forum category created successfully.');
    }

    public function edit(Request $request, ForumCategory $category): Response
    {
        abort_unless($request->user()?->can('forums.acp.edit'), 403);

        return inertia('acp/ForumCategoryEdit', [
            'category' => [
                'id' => $category->id,
                'title' => $category->title,
                'slug' => $category->slug,
                'description' => $category->description,
            ],
        ]);
    }

    public function update(UpdateForumCategoryRequest $request, ForumCategory $category): RedirectResponse
    {
        $validated = $request->validated();

        $category->forceFill([
            'title' => $validated['title'],
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['title'], ForumCategory::class, $category->id),
            'description' => $validated['description'] ?? null,
        ])->save();

        return redirect()
            ->route('acp.forums.index')
            ->with('success', 'Forum category updated successfully.');
    }

    public function destroy(Request $request, ForumCategory $category): RedirectResponse
    {
        abort_unless($request->user()?->can('forums.acp.delete'), 403);

        $category->delete();
        $this->resequenceCategories();

        return redirect()
            ->route('acp.forums.index')
            ->with('success', 'Forum category deleted successfully.');
    }

    public function reorder(ReorderForumCategoryRequest $request, ForumCategory $category): RedirectResponse
    {
        $direction = $request->validated()['direction'];

        $neighbor = ForumCategory::query()
            ->when(
                $direction === 'up',
                fn ($query) => $query->where('position', '<', $category->position)->orderByDesc('position'),
                fn ($query) => $query->where('position', '>', $category->position)->orderBy('position')
            )
            ->first();

        if ($neighbor) {
            $this->swapPositions($category, $neighbor);
        }

        return redirect()
            ->back()
            ->with('success', 'Forum categories reordered successfully.');
    }
}
