<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PollController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $polls = Poll::query()
            ->whereIn('status', ['published', 'closed'])
            ->with(['options' => fn ($query) => $query->withCount('votes')])
            ->withCount('votes')
            ->orderByDesc('created_at')
            ->get();

        $userVotes = $this->loadUserVotes($request->user(), $polls);

        return response()->json([
            'polls' => $polls->map(fn (Poll $poll) => $this->serializePoll($poll, $userVotes)),
        ]);
    }

    public function show(Request $request, Poll $poll): JsonResponse
    {
        if (! in_array($poll->status, ['published', 'closed'], true)) {
            abort(404);
        }

        $poll->load(['options' => fn ($query) => $query->withCount('votes')]);
        $poll->loadCount('votes');

        $userVotes = $this->loadUserVotes($request->user(), collect([$poll]));

        return response()->json([
            'poll' => $this->serializePoll($poll, $userVotes),
        ]);
    }

    protected function loadUserVotes(?User $user, Collection $polls): Collection
    {
        if (! $user) {
            return collect();
        }

        return PollVote::query()
            ->where('user_id', $user->id)
            ->whereIn('poll_id', $polls->pluck('id'))
            ->get()
            ->groupBy('poll_id');
    }

    protected function serializePoll(Poll $poll, Collection $userVotes): array
    {
        $totalVotes = $poll->votes_count ?? $poll->votes()->count();
        $userVotesForPoll = $userVotes->get($poll->id, collect());
        $userVoteOptionIds = $userVotesForPoll->pluck('poll_option_id')->values()->all();

        return [
            'id' => $poll->id,
            'title' => $poll->title,
            'slug' => $poll->slug,
            'description' => $poll->description,
            'status' => $poll->status,
            'allow_multiple' => $poll->allow_multiple,
            'starts_at' => $poll->starts_at?->toIso8601String(),
            'ends_at' => $poll->ends_at?->toIso8601String(),
            'total_votes' => $totalVotes,
            'user_vote_option_ids' => $userVoteOptionIds,
            'can_vote' => $poll->isOpen() && ($poll->allow_multiple || $userVoteOptionIds === []),
            'options' => $poll->options->map(fn ($option) => [
                'id' => $option->id,
                'label' => $option->label,
                'votes_count' => $option->votes_count ?? 0,
                'vote_percent' => $totalVotes > 0
                    ? round(($option->votes_count ?? 0) / $totalVotes * 100, 1)
                    : 0,
            ])->values()->all(),
        ];
    }
}
