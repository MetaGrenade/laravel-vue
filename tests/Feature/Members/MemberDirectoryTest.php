<?php

namespace Tests\Feature\Members;

use App\Models\Badge;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Members\Members;
use App\Support\WebsiteSections;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The public member directory and profiles (docs/members.md): who is listed, what a profile shows, and what it never shows.
 */
class MemberDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private ForumBoard $board;

    protected function setUp(): void
    {
        parent::setUp();

        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Chat', 'position' => 1]);
        $this->board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'The lounge',
            'slug' => 'the-lounge',
            'description' => 'Chat',
            'position' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $over
     */
    private function thread(User $author, string $title, array $over = []): ForumThread
    {
        $thread = new ForumThread([
            'forum_board_id' => $this->board->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::random(5),
            'excerpt' => $title,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
        ]);
        $thread->forceFill($over)->save();

        return $thread;
    }

    /**
     * @param  array<string, mixed>  $over
     */
    private function reply(ForumThread $thread, User $author, string $body, array $over = []): ForumPost
    {
        $post = new ForumPost(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => '<p>'.$body.'</p>']);
        $post->forceFill($over)->save();

        return $post;
    }

    #[Test]
    public function the_directory_lists_members_and_leaves_out_banned_accounts_and_email_addresses(): void
    {
        $ada = User::factory()->create([
            'nickname' => 'Ada Lovelace',
            'email' => 'ada-secret@example.test',
            'profile_bio' => 'Writes notes.',
            'reputation_points' => 12,
            'created_at' => now()->subDay(),
        ]);
        $zoe = User::factory()->create([
            'nickname' => 'Zoe Builder',
            'email' => 'zoe-secret@example.test',
            'reputation_points' => 3,
            'created_at' => now(),
        ]);
        $banned = User::factory()->create([
            'nickname' => 'Banned Spammer',
            'email' => 'spam-secret@example.test',
            'profile_bio' => 'Buy watches',
            'is_banned' => true,
            'ban_reason' => 'Spam wave',
        ]);

        $this->get(route('members.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Members')
                ->where('sort', 'newest')
                ->where('members.data.0.nickname', 'Zoe Builder')
                ->where('members.data.1.nickname', 'Ada Lovelace')
                ->where('members.data.0.reputation', 3)
                ->where('members.data.1.bio', 'Writes notes.')
                ->missing('members.data.0.email')
                ->missing('members.data.1.email'))
            ->assertDontSee('ada-secret@example.test', false)
            ->assertDontSee('zoe-secret@example.test', false)
            ->assertDontSee('Banned Spammer', false)
            ->assertDontSee('Buy watches', false)
            ->assertDontSee('Spam wave', false);

        $this->get(route('members.index', ['q' => 'ada']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('members.data', 1)
                ->where('members.data.0.id', $ada->id));

        $this->get(route('members.index', ['q' => 'ada-secret']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('members.data', 0));

        $this->get(route('members.index', ['sort' => 'reputation']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort', 'reputation')
                ->where('members.data.0.nickname', 'Ada Lovelace')
                ->where('members.data.1.nickname', 'Zoe Builder'));

        $this->get(route('members.index', ['sort' => 'name']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('members.data.0.nickname', 'Ada Lovelace')
                ->where('members.data.1.nickname', 'Zoe Builder'));

        $this->get(route('members.index', ['sort' => 'nope']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort', 'newest')
                ->where('members.data.0.nickname', 'Zoe Builder'));

        $this->get(route('members.show', $banned))->assertNotFound();
        $this->get(route('members.show', $zoe))->assertOk();
    }

    #[Test]
    public function a_profile_shows_what_is_public_and_nothing_that_was_taken_down(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $member = User::factory()->create([
            'nickname' => 'Ada Lovelace',
            'email' => 'ada-secret@example.test',
            'profile_bio' => "Writes notes.\n<script>alert(1)</script>",
            'forum_signature' => 'First programmer',
            'avatar_url' => 'javascript:alert(1)',
            'reputation_points' => 40,
            'social_links' => [
                ['label' => 'Notes', 'url' => 'https://notes.example/ada'],
                ['label' => 'Bad', 'url' => 'javascript:alert(1)'],
                ['label' => 'Login', 'url' => 'https://user:pass@example.test/secret'],
            ],
            'timezone' => 'Pacific/Auckland',
        ]);
        $member->assignRole('moderator');
        $badge = Badge::create(['name' => 'Founder', 'slug' => 'founder', 'description' => 'Was here first', 'points_required' => 0, 'is_active' => true]);
        $member->badges()->attach($badge);

        $other = User::factory()->create(['nickname' => 'Someone Else']);
        $own = $this->thread($member, 'Public thread');
        $this->reply($own, $member, 'The opening post');
        $this->reply($own, $member, 'A follow-up in my own thread');
        $this->thread($member, 'Unpublished secret thread', ['is_published' => false]);
        $theirs = $this->thread($other, 'Their question');
        $this->reply($theirs, $member, 'A useful answer');
        $deleted = $this->reply($theirs, $member, 'deleted secret phrase');
        $deleted->delete();
        $this->reply($theirs, $member, 'held secret phrase', ['held_at' => now(), 'held_reason' => 'links']);

        $published = Blog::factory()->published()->create();
        BlogComment::factory()->for($published)->for($member)->create(['body' => 'A real comment', 'status' => BlogComment::STATUS_APPROVED]);
        BlogComment::factory()->for($published)->for($member)->create(['body' => 'rejected secret comment', 'status' => BlogComment::STATUS_REJECTED]);
        BlogComment::factory()->for(Blog::factory()->create())->for($member)->create(['body' => 'draft secret comment', 'status' => BlogComment::STATUS_APPROVED]);

        $this->get(route('members.show', $member))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Member')
                ->where('member.nickname', 'Ada Lovelace')
                ->where('member.role', 'Moderator')
                ->where('member.reputation', 40)
                ->where('member.bio', "Writes notes.\nalert(1)")
                ->where('member.signature', 'First programmer')
                ->where('member.avatar_url', null)
                ->where('member.badges.0.name', 'Founder')
                ->where('member.links', [['label' => 'Notes', 'url' => 'https://notes.example/ada']])
                ->where('member.activity.forum.thread_count', 1)
                ->where('member.activity.forum.reply_count', 1)
                ->where('member.activity.forum.threads.0.title', 'Public thread')
                ->where('member.activity.forum.replies.0.thread', 'Their question')
                ->where('member.activity.forum.replies.0.excerpt', 'A useful answer')
                ->where('member.activity.blog.comment_count', 1)
                ->missing('member.email'))
            ->assertDontSee('ada-secret@example.test', false)
            ->assertDontSee('Pacific/Auckland', false)
            ->assertDontSee('Unpublished secret thread', false)
            ->assertDontSee('deleted secret phrase', false)
            ->assertDontSee('held secret phrase', false)
            ->assertDontSee('rejected secret comment', false)
            ->assertDontSee('draft secret comment', false)
            ->assertDontSee('javascript:', false)
            ->assertDontSee('<script>', false)
            ->assertDontSee('user:pass', false);
    }

    #[Test]
    public function a_profile_leaves_out_the_forum_when_the_forum_is_switched_off(): void
    {
        $member = User::factory()->create(['nickname' => 'Ada Lovelace', 'forum_signature' => 'First programmer']);
        $this->thread($member, 'Public thread');

        $sections = WebsiteSections::defaults();
        $sections[WebsiteSections::FORUM] = false;
        $sections[WebsiteSections::BLOG] = false;
        SystemSetting::set('website_sections', $sections);

        $this->get(route('members.show', $member))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('member.signature', null)
                ->where('member.activity.forum', null)
                ->where('member.activity.blog', null))
            ->assertDontSee('Public thread', false);
    }

    #[Test]
    public function the_directory_pages_and_the_sitemap_include_only_accounts_that_are_not_banned(): void
    {
        foreach (range(1, Members::PAGE_SIZE + 1) as $number) {
            User::factory()->create([
                'nickname' => sprintf('Paged Member %02d', $number),
                'created_at' => now()->subMinutes(Members::PAGE_SIZE + 2 - $number),
            ]);
        }

        $banned = User::factory()->create(['nickname' => 'Banned Spammer', 'is_banned' => true]);

        $this->get(route('members.index', ['page' => 2]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('members.meta.current_page', 2)
                ->where('members.meta.last_page', 2)
                ->has('members.data', 1)
                ->where('members.data.0.nickname', 'Paged Member 01'));

        $listed = User::query()->where('nickname', 'Paged Member 01')->firstOrFail();

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>'.e(route('members.index')).'</loc>', false)
            ->assertSee('<loc>'.e(route('members.show', $listed)).'</loc>', false)
            ->assertDontSee('<loc>'.e(route('members.show', $banned)).'</loc>', false);
    }
}
