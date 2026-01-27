<?php

namespace Database\Seeders;

use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Database\Seeder;

class PollDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->first();

        if (! $user) {
            return;
        }

        $poll = Poll::updateOrCreate(
            ['slug' => 'favorite-product-update'],
            [
                'user_id' => $user->id,
                'title' => 'Which product update excites you most?',
                'description' => 'Help us prioritize upcoming releases by voting for the update you want to see next.',
                'status' => 'published',
                'allow_multiple' => false,
                'starts_at' => now()->subDays(2),
                'ends_at' => now()->addDays(5),
            ]
        );

        $optionLabels = [
            'Real-time collaboration',
            'Advanced analytics dashboard',
            'Mobile offline mode',
            'New automation templates',
        ];

        $poll->options()->delete();

        $options = collect($optionLabels)
            ->map(fn (string $label, int $index) => PollOption::create([
                'poll_id' => $poll->id,
                'label' => $label,
                'sort_order' => $index + 1,
            ]));

        $voters = User::query()->take(6)->get();

        PollVote::query()->where('poll_id', $poll->id)->delete();

        foreach ($voters as $voter) {
            $option = $options->random();

            PollVote::create([
                'poll_id' => $poll->id,
                'poll_option_id' => $option->id,
                'user_id' => $voter->id,
            ]);
        }
    }
}
