<?php

namespace Database\Seeders;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ForumDemoSeeder extends Seeder
{
    public function run(): void
    {
        Schema::disableForeignKeyConstraints();
        ForumPost::truncate();
        ForumThread::truncate();
        ForumBoard::truncate();
        ForumCategory::truncate();
        Schema::enableForeignKeyConstraints();

        $users = User::factory()->count(6)->create();

        $categoryDefinitions = [
            [
                'title' => 'Community Hub',
                'description' => 'Introduce yourself and chat about anything with fellow members.',
                'boards' => [
                    [
                        'title' => 'Announcements',
                        'description' => 'Official news, updates, and release information.',
                        'pinned_count' => 1,
                    ],
                    [
                        'title' => 'General Discussion',
                        'description' => 'Talk about anything and everything here.',
                    ],
                ],
            ],
            [
                'title' => 'Support & Feedback',
                'description' => 'Get help from the community or share suggestions for improvement.',
                'boards' => [
                    [
                        'title' => 'Help Desk',
                        'description' => 'Questions, troubleshooting, and player-to-player assistance.',
                    ],
                    [
                        'title' => 'Feature Requests',
                        'description' => 'Share ideas to make the experience even better.',
                    ],
                ],
            ],
        ];

        $now = Carbon::now();
        $threadSeedBodies = [
            'Welcome to the new forums! We are excited to have you here. Share your thoughts and let us know what you think about the latest update.',
            'If you are running into any issues please describe them here and include screenshots when possible so the team can investigate quickly.',
            'What features would you love to see next? We are collecting feedback for our roadmap and want to hear from you.',
            'This thread is a great place to meet other community members. Say hello and tell everyone your favorite part of the project so far!',
        ];

        foreach ($categoryDefinitions as $categoryIndex => $definition) {
            $category = ForumCategory::create([
                'title' => $definition['title'],
                'slug' => Str::slug($definition['title']),
                'description' => $definition['description'],
                'position' => $categoryIndex,
            ]);

            foreach ($definition['boards'] as $boardIndex => $boardDefinition) {
                $board = ForumBoard::create([
                    'forum_category_id' => $category->id,
                    'title' => $boardDefinition['title'],
                    'slug' => Str::slug($boardDefinition['title']) . '-' . Str::random(5),
                    'description' => $boardDefinition['description'],
                    'position' => $boardIndex,
                ]);

                $threadCount = 3;

                for ($threadIndex = 0; $threadIndex < $threadCount; $threadIndex++) {
                    $author = $users->random();
                    $primaryBody = $threadSeedBodies[array_rand($threadSeedBodies)];
                    $title = $threadIndex === 0 && ($boardDefinition['pinned_count'] ?? 0) > 0
                        ? 'Read First: ' . $board->title . ' Guidelines'
                        : Str::headline(Str::words($primaryBody, 6, ''));

                    $threadStartedAt = $now->copy()->subDays(random_int(0, 10));
                    $currentTimestamp = $threadStartedAt->copy();

                    $thread = ForumThread::create([
                        'forum_board_id' => $board->id,
                        'user_id' => $author->id,
                        'title' => $title,
                        'slug' => Str::slug($title) . '-' . Str::random(6),
                        'excerpt' => Str::limit($primaryBody, 160),
                        'is_locked' => false,
                        'is_pinned' => $threadIndex === 0 && ($boardDefinition['pinned_count'] ?? 0) > 0,
                        'views' => random_int(25, 750),
                        'last_posted_at' => $currentTimestamp,
                        'last_post_user_id' => $author->id,
                    ]);

                    $postTotal = random_int(2, 5);
                    $lastPost = null;

                    for ($postIndex = 0; $postIndex < $postTotal; $postIndex++) {
                        if ($postIndex > 0) {
                            $currentTimestamp = $currentTimestamp->copy()->addMinutes(random_int(15, 240));
                        }

                        $postAuthor = $postIndex === 0 ? $author : $users->random();
                        $body = $postIndex === 0 ? $primaryBody : $threadSeedBodies[array_rand($threadSeedBodies)];

                        $lastPost = ForumPost::create([
                            'forum_thread_id' => $thread->id,
                            'user_id' => $postAuthor->id,
                            'body' => $body,
                            'created_at' => $currentTimestamp,
                            'updated_at' => $currentTimestamp,
                        ]);
                    }

                    if ($lastPost) {
                        $thread->timestamps = false;
                        $thread->forceFill([
                            'created_at' => $threadStartedAt,
                            'last_posted_at' => $lastPost->created_at,
                            'last_post_user_id' => $lastPost->user_id,
                            'updated_at' => $lastPost->created_at,
                        ])->save();
                        $thread->timestamps = true;
                    }
                }
            }
        }
    }
}
