<?php

namespace App\Http\Controllers;

use App\Models\Poll;
use App\Support\Localization\DateFormatter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WelcomeController extends Controller
{
    public function index(Request $request): Response
    {
        $formatter = DateFormatter::for($request->user());

        // Fetch only open polls (published and within date range)
        $polls = Poll::query()
            ->where('status', 'published')
            ->with(['options' => fn ($query) => $query->withCount('votes')->orderBy('sort_order')])
            ->get()
            ->filter(fn (Poll $poll) => $poll->isOpen())
            ->take(10)
            ->map(function (Poll $poll) use ($formatter) {
                $options = $poll->options;
                $totalVotes = $options->sum(fn ($option) => $option->votes_count ?? 0);

                return [
                    'id' => $poll->id,
                    'title' => $poll->title,
                    'description' => $poll->description,
                    'endsAt' => $poll->ends_at ? $formatter->date($poll->ends_at) : null,
                    'totalVotes' => $totalVotes,
                    'options' => $options->map(function ($option) use ($totalVotes) {
                        $votesCount = $option->votes_count ?? 0;
                        return [
                            'id' => $option->id,
                            'label' => $option->label,
                            'votesCount' => $votesCount,
                            'votePercent' => $totalVotes > 0
                                ? round(($votesCount / $totalVotes) * 100, 1)
                                : 0,
                        ];
                    })->values()->all(),
                ];
            })
            ->values();

        return Inertia::render('Welcome', [
            'activePolls' => $polls,
        ]);
    }
}
