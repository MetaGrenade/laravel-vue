<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PollRequest;
use App\Models\Poll;
use App\Models\PollOption;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Response;

class PollController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $formatter = DateFormatter::for($request->user());

        $polls = Poll::query()
            ->withCount(['options', 'votes'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Poll $poll) => [
                'id' => $poll->id,
                'title' => $poll->title,
                'slug' => $poll->slug,
                'status' => $poll->status,
                'options_count' => $poll->options_count ?? 0,
                'votes_count' => $poll->votes_count ?? 0,
                'allow_multiple' => $poll->allow_multiple,
                'starts_at' => $formatter->iso($poll->starts_at),
                'ends_at' => $formatter->iso($poll->ends_at),
                'created_at' => $formatter->iso($poll->created_at),
                'updated_at' => $formatter->iso($poll->updated_at),
            ])
            ->values()
            ->all();

        if ($request->wantsJson()) {
            return response()->json(['polls' => $polls]);
        }

        return inertia('acp/Polls', [
            'polls' => $polls,
        ]);
    }

    public function create(): Response
    {
        return inertia('acp/PollCreate');
    }

    public function store(PollRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $poll = Poll::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['title']),
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'allow_multiple' => $validated['allow_multiple'] ?? false,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
        ]);

        $options = collect($validated['options'])->values();

        $poll->options()->createMany(
            $options->map(fn (array $option, int $index) => [
                'label' => $option['label'],
                'sort_order' => $index + 1,
            ])->all()
        );

        return redirect()
            ->route('acp.polls.index')
            ->with('success', 'Poll created successfully.');
    }

    public function edit(Poll $poll): Response
    {
        $poll->load(['options' => fn ($query) => $query->withCount('votes')]);
        $formatter = DateFormatter::for(request()->user());

        $totalVotes = $poll->options->sum('votes_count');

        return inertia('acp/PollEdit', [
            'poll' => [
                'id' => $poll->id,
                'title' => $poll->title,
                'slug' => $poll->slug,
                'description' => $poll->description,
                'status' => $poll->status,
                'allow_multiple' => $poll->allow_multiple,
                'starts_at' => $formatter->iso($poll->starts_at),
                'ends_at' => $formatter->iso($poll->ends_at),
                'created_at' => $formatter->iso($poll->created_at),
                'updated_at' => $formatter->iso($poll->updated_at),
                'total_votes' => $totalVotes,
                'options' => $poll->options->map(fn (PollOption $option) => [
                    'id' => $option->id,
                    'label' => $option->label,
                    'votes_count' => $option->votes_count ?? 0,
                ])->values()->all(),
            ],
        ]);
    }

    public function update(PollRequest $request, Poll $poll): RedirectResponse
    {
        $validated = $request->validated();

        $poll->forceFill([
            'title' => $validated['title'],
            'slug' => $this->resolveSlug($validated['slug'] ?? null, $validated['title'], $poll->id),
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'],
            'allow_multiple' => $validated['allow_multiple'] ?? false,
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
        ])->save();

        $options = collect($validated['options'])->values();
        $existingOptions = $poll->options()->get()->keyBy('id');
        $incomingIds = $options->pluck('id')->filter()->values();

        $invalidIds = $incomingIds->diff($existingOptions->keys());

        if ($invalidIds->isNotEmpty()) {
            throw ValidationException::withMessages([
                'options' => 'One or more options are invalid.',
            ]);
        }

        if ($incomingIds->isEmpty()) {
            $poll->options()->delete();
        } else {
            $poll->options()->whereNotIn('id', $incomingIds)->delete();
        }

        $options->each(function (array $option, int $index) use ($poll, $existingOptions) {
            if (isset($option['id']) && $existingOptions->has($option['id'])) {
                $existingOptions[$option['id']]->forceFill([
                    'label' => $option['label'],
                    'sort_order' => $index + 1,
                ])->save();

                return;
            }

            $poll->options()->create([
                'label' => $option['label'],
                'sort_order' => $index + 1,
            ]);
        });

        return redirect()
            ->route('acp.polls.index')
            ->with('success', 'Poll updated successfully.');
    }

    public function destroy(Poll $poll): RedirectResponse
    {
        $poll->delete();

        return redirect()
            ->route('acp.polls.index')
            ->with('success', 'Poll deleted successfully.');
    }

    protected function resolveSlug(?string $slug, string $title, ?int $ignoreId = null): string
    {
        $candidate = Str::slug($slug ?: $title);

        if ($candidate === '') {
            $candidate = Str::random(8);
        }

        $original = $candidate;
        $suffix = 1;

        $query = Poll::query()->where('slug', $candidate);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        while ($query->exists()) {
            $candidate = $original.'-'.$suffix++;

            $query = Poll::query()->where('slug', $candidate);

            if ($ignoreId) {
                $query->where('id', '!=', $ignoreId);
            }
        }

        return $candidate;
    }
}
