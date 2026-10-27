<?php

namespace Database\Seeders;

use App\Models\Blog;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * The few public records the accessibility audit opens (docs/accessibility.md).
 * Nothing here is demo content for a person using the site.
 */
class AccessibilityFlowSeeder extends Seeder
{
    public function run(): void
    {
        $member = User::factory()->create([
            'nickname' => 'Avery Cole',
            'email' => 'avery@example.com',
            'profile_bio' => 'A member whose profile the accessibility audit opens.',
        ]);

        Product::factory()->priced('10.00')->stocked(5)->create([
            'name' => 'Hoodie',
            'slug' => 'hoodie',
            'description' => 'A warm hoodie.',
        ]);

        $category = ForumCategory::create([
            'title' => 'General',
            'slug' => 'general',
            'description' => 'General discussions',
            'position' => 1,
        ]);

        $board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Announcements',
            'slug' => 'announcements',
            'description' => 'Forum announcements',
            'position' => 1,
        ]);

        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $member->id,
            'title' => 'Welcome',
            'slug' => 'welcome',
            'excerpt' => null,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $member->id,
        ]);

        ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $member->id,
            'body' => 'Hello from the forum.',
        ]);

        Blog::factory()->published()->for($member)->create([
            'title' => 'A first post',
            'slug' => 'a-first-post',
            'excerpt' => 'What the post is about.',
            'body' => 'The post itself.',
        ]);
    }
}
