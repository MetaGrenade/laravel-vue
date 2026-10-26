<?php

namespace App\Http\Controllers;

use App\Support\Localization\DateFormatter;
use App\Support\Reputation\Leaderboard;
use App\Support\Seo\Seo;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public leaderboard (docs/leaderboard.md).
 */
class LeaderboardController extends Controller
{
    public function index(Request $request, Leaderboard $leaderboard, Seo $seo): Response
    {
        $seo->title(__('members.leaderboard.title'))
            ->description(__('members.leaderboard.meta'))
            ->canonical(route('leaderboard'))
            ->schema([
                '@type' => 'CollectionPage',
                'name' => __('members.leaderboard.title'),
                'url' => route('leaderboard'),
            ]);

        return Inertia::render('Leaderboard', [
            'standings' => $leaderboard->standings(),
            'activity' => $leaderboard->activity(DateFormatter::for($request->user())),
        ]);
    }
}
