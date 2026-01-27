<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PollVoteController extends Controller
{
    public function store(Request $request, Poll $poll): JsonResponse
    {
        if (! $poll->isOpen()) {
            return response()->json([
                'message' => 'This poll is not open for voting.',
            ], 422);
        }

        $validated = $request->validate([
            'option_id' => [
                'required',
                'integer',
                Rule::exists('poll_options', 'id')->where('poll_id', $poll->id),
            ],
        ]);

        $user = $request->user();

        $existingVotes = PollVote::query()
            ->where('poll_id', $poll->id)
            ->where('user_id', $user->id)
            ->get();

        if (! $poll->allow_multiple && $existingVotes->isNotEmpty()) {
            return response()->json([
                'message' => 'You have already voted in this poll.',
            ], 409);
        }

        if ($poll->allow_multiple && $existingVotes->contains('poll_option_id', $validated['option_id'])) {
            return response()->json([
                'message' => 'You have already voted for this option.',
            ], 409);
        }

        PollVote::create([
            'poll_id' => $poll->id,
            'poll_option_id' => $validated['option_id'],
            'user_id' => $user->id,
        ]);

        $poll->load(['options' => fn ($query) => $query->withCount('votes')]);
        $poll->loadCount('votes');

        $totalVotes = $poll->votes_count ?? 0;

        return response()->json([
            'message' => 'Vote recorded successfully.',
            'poll' => [
                'id' => $poll->id,
                'slug' => $poll->slug,
                'status' => $poll->status,
                'total_votes' => $totalVotes,
                'options' => $poll->options->map(fn (PollOption $option) => [
                    'id' => $option->id,
                    'label' => $option->label,
                    'votes_count' => $option->votes_count ?? 0,
                    'vote_percent' => $totalVotes > 0
                        ? round(($option->votes_count ?? 0) / $totalVotes * 100, 1)
                        : 0,
                ])->values()->all(),
            ],
        ]);
    }
}
