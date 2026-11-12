<?php

namespace Tests\Feature\Admin;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumPostReport;
use App\Models\ForumThread;
use App\Models\ForumThreadReport;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The list of what members reported in the staff area: reports of threads and of posts, two tables shown as one list, newest first.
 *
 * The database counts it and cuts the page out of it (docs/performance.md): it used to read every report that matched, with its thread, board,
 * post and people, to show twenty-five of them, and took 2.9 seconds at 10,000 reports.
 */
class ForumReportListTest extends TestCase
{
    use RefreshDatabase;

    private ?User $moderator = null;

    private ?ForumBoard $board = null;

    private int $made = 0;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function moderator(): User
    {
        if ($this->moderator === null) {
            $role = Role::findOrCreate('moderator');
            $role->givePermissionTo(Permission::findOrCreate('forums.acp.view'));

            $this->moderator = User::factory()->create();
            $this->moderator->assignRole($role);
        }

        return $this->moderator;
    }

    private function board(string $title = 'Announcements'): ForumBoard
    {
        $category = ForumCategory::query()->firstOrCreate(
            ['slug' => 'general'],
            ['title' => 'General', 'description' => 'General talk', 'position' => 1],
        );

        return ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => $title,
            'slug' => str($title)->slug()->value(),
            'description' => $title,
            'position' => ++$this->made,
        ]);
    }

    private function thread(?ForumBoard $board = null, string $title = 'A thread', ?User $author = null): ForumThread
    {
        $this->board ??= $this->board();
        $number = ++$this->made;

        return ForumThread::create([
            'forum_board_id' => ($board ?? $this->board)->id,
            'user_id' => ($author ?? User::factory()->create())->id,
            'title' => $title,
            'slug' => str($title)->slug().'-'.$number,
            'excerpt' => 'Thread under review',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
        ]);
    }

    private function reply(?ForumThread $thread = null, string $body = '<p>A reply</p>', ?User $author = null): ForumPost
    {
        return ForumPost::create([
            'forum_thread_id' => ($thread ?? $this->thread())->id,
            'user_id' => ($author ?? User::factory()->create())->id,
            'body' => $body,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function reportOfThread(ForumThread $thread, string $at, array $attributes = []): ForumThreadReport
    {
        $report = (new ForumThreadReport)->forceFill($attributes + [
            'forum_thread_id' => $thread->id,
            'reporter_id' => User::factory()->create()->id,
            'reason_category' => 'spam',
            'reason' => 'Looks like spam.',
            'status' => ForumThreadReport::STATUS_PENDING,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $report->save();

        return $report;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function reportOfPost(ForumPost $post, string $at, array $attributes = []): ForumPostReport
    {
        $report = (new ForumPostReport)->forceFill($attributes + [
            'forum_post_id' => $post->id,
            'reporter_id' => User::factory()->create()->id,
            'reason_category' => 'spam',
            'reason' => 'Looks like spam.',
            'status' => ForumPostReport::STATUS_PENDING,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $report->save();

        return $report;
    }

    /**
     * The reports a request lists, in order, as `t12` (the report 12 of a thread) and `p3` (the report 3 of a post).
     *
     * @return list<string>
     */
    private function listed(string $query = ''): array
    {
        $rows = null;

        $this->actingAs($this->moderator())
            ->get(route('acp.forums.reports.index').'?'.$query)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows) {
                $page->component('acp/ForumReports')->where('reports.data', function ($data) use (&$rows) {
                    $rows = collect($data)->map(fn (array $row) => $row['type'][0].$row['id'])->all();

                    return true;
                });
            });

        return $rows;
    }

    /**
     * How many queries the request reads whole reports from `$table` with (the page is picked first, then only its reports are read). Not the
     * one that picks the page, nor the ones that count.
     */
    private function readsOf(string $table, \Closure $request): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $request();

            return collect(DB::getQueryLog())
                ->filter(fn (array $query) => preg_match('/^select \* from [`"]'.$table.'[`"]/', $query['query']) === 1)
                ->count();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }
    }

    #[Test]
    public function threads_and_posts_are_one_list_newest_first_over_as_many_pages_as_it_takes(): void
    {
        $thread = $this->thread();
        $post = $this->reply($thread);

        // Made in an order that is neither all of one kind first nor the order of the ids.
        $p1 = $this->reportOfPost($post, '2026-03-01 10:00:00');
        $t1 = $this->reportOfThread($thread, '2026-03-01 11:00:00');
        $t2 = $this->reportOfThread($this->thread(), '2026-03-01 12:00:00');
        $p2 = $this->reportOfPost($this->reply($thread), '2026-03-01 13:00:00');
        $p3 = $this->reportOfPost($this->reply($thread), '2026-03-01 14:00:00');
        $t3 = $this->reportOfThread($this->thread(), '2026-03-01 09:00:00');

        $expected = ['p'.$p3->id, 'p'.$p2->id, 't'.$t2->id, 't'.$t1->id, 'p'.$p1->id, 't'.$t3->id];

        $this->assertSame($expected, $this->listed('per_page=10'));
        $this->assertSame(array_slice($expected, 0, 5), $this->listed('per_page=5'));
        $this->assertSame(array_slice($expected, 5), $this->listed('per_page=5&page=2'));
    }

    #[Test]
    public function the_pages_know_how_many_reports_there_are_in_all(): void
    {
        $thread = $this->thread();

        foreach (range(1, 7) as $hour) {
            $this->reportOfThread($thread, sprintf('2026-03-01 %02d:00:00', $hour));
            $this->reportOfPost($this->reply($thread), sprintf('2026-03-01 %02d:30:00', $hour));
        }

        $this->actingAs($this->moderator())
            ->get(route('acp.forums.reports.index', ['per_page' => 5, 'page' => 3]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reports.meta.total', 14)
                ->where('reports.meta.per_page', 5)
                ->where('reports.meta.current_page', 3)
                ->where('reports.meta.last_page', 3)
                ->where('reports.meta.from', 11)
                ->where('reports.meta.to', 14)
                ->where('reports.links.prev', route('acp.forums.reports.index', ['per_page' => 5, 'page' => 2]))
                ->where('reports.links.next', null)
                ->has('reports.data', 4));
    }

    #[Test]
    public function a_page_past_the_end_is_empty_and_still_says_how_many_there_are(): void
    {
        $this->reportOfThread($this->thread(), '2026-03-01 10:00:00');

        $this->assertSame([], $this->listed('page=9'));

        $this->actingAs($this->moderator())
            ->get(route('acp.forums.reports.index', ['page' => 9]))
            ->assertInertia(fn (Assert $page) => $page->where('reports.meta.total', 1)->where('reports.meta.last_page', 1));
    }

    #[Test]
    public function reports_made_in_the_same_second_list_a_threads_before_a_posts_and_the_later_report_first(): void
    {
        $thread = $this->thread();
        $post = $this->reply($thread);

        $firstOfThread = $this->reportOfThread($thread, '2026-03-01 10:00:00');
        $firstOfPost = $this->reportOfPost($post, '2026-03-01 10:00:00');
        $laterOfThread = $this->reportOfThread($this->thread(), '2026-03-01 10:00:00');
        $laterOfPost = $this->reportOfPost($this->reply($thread), '2026-03-01 10:00:00');

        // The same order whichever way the database happens to walk the table, so a report does not move from page to page.
        $this->assertSame(
            ['t'.$laterOfThread->id, 't'.$firstOfThread->id, 'p'.$laterOfPost->id, 'p'.$firstOfPost->id],
            $this->listed(),
        );
    }

    #[Test]
    public function only_the_reports_of_the_page_are_read_in_full(): void
    {
        $thread = $this->thread();

        foreach (range(1, 20) as $minute) {
            $this->reportOfThread($this->thread(), sprintf('2026-03-01 10:%02d:00', $minute));
            $this->reportOfPost($this->reply($thread), sprintf('2026-03-01 10:%02d:30', $minute));
        }

        $read = 0;
        ForumThreadReport::retrieved(function () use (&$read) {
            $read++;
        });
        ForumPostReport::retrieved(function () use (&$read) {
            $read++;
        });

        $this->assertCount(5, $this->listed('per_page=5'));

        // 40 reports match; five of them are on the page.
        $this->assertSame(5, $read);
    }

    #[Test]
    public function a_kind_of_report_that_is_not_on_the_page_is_not_read_at_all(): void
    {
        $thread = $this->thread();
        $olderReportOfPost = $this->reportOfPost($this->reply($thread), '2026-03-01 08:00:00');
        foreach (range(10, 14) as $hour) {
            $this->reportOfThread($this->thread(), sprintf('2026-03-01 %02d:00:00', $hour));
        }

        $reads = fn (string $table, string $query) => $this->readsOf($table, fn () => $this->listed($query));

        // The page is five threads' reports: no post report is read, nor the posts, their authors or the threads they are in.
        $this->assertSame(0, $reads('forum_post_reports', 'per_page=5'));
        $this->assertSame(1, $reads('forum_thread_reports', 'per_page=5'));

        // The next page is the one post report: no thread report is read.
        $this->assertSame(['p'.$olderReportOfPost->id], $this->listed('per_page=5&page=2'));
        $this->assertSame(0, $reads('forum_thread_reports', 'per_page=5&page=2'));
        $this->assertSame(1, $reads('forum_post_reports', 'per_page=5&page=2'));
    }

    #[Test]
    public function a_report_deleted_while_the_page_is_being_made_leaves_a_short_page(): void
    {
        $thread = $this->thread();
        $first = $this->reportOfThread($thread, '2026-03-01 10:00:00');
        $second = $this->reportOfThread($this->thread(), '2026-03-01 11:00:00');
        $post = $this->reportOfPost($this->reply($thread), '2026-03-01 12:00:00');

        // Between the query that picks the page (the one with a limit; the one before it only counts) and the ones that read it, somebody
        // deletes the report of a thread and the report of a post.
        $deleted = false;
        DB::listen(function (QueryExecuted $query) use (&$deleted, $second, $post) {
            $sql = strtolower($query->sql);

            if (! $deleted && str_contains($sql, 'union all') && str_contains($sql, ' limit ')) {
                $deleted = true;
                DB::table('forum_thread_reports')->where('id', $second->id)->delete();
                DB::table('forum_post_reports')->where('id', $post->id)->delete();
            }
        });

        $this->assertSame(['t'.$first->id], $this->listed());
        $this->assertTrue($deleted);
    }

    #[Test]
    public function only_the_pending_reports_are_listed_unless_another_status_is_asked_for(): void
    {
        $thread = $this->thread();
        $post = $this->reply($thread);

        $pendingThread = $this->reportOfThread($thread, '2026-03-01 10:00:00');
        $reviewedThread = $this->reportOfThread($this->thread(), '2026-03-01 11:00:00', ['status' => 'reviewed']);
        $dismissedThread = $this->reportOfThread($this->thread(), '2026-03-01 12:00:00', ['status' => 'dismissed']);
        $pendingPost = $this->reportOfPost($post, '2026-03-01 13:00:00');
        $reviewedPost = $this->reportOfPost($this->reply($thread), '2026-03-01 14:00:00', ['status' => 'reviewed']);
        $dismissedPost = $this->reportOfPost($this->reply($thread), '2026-03-01 15:00:00', ['status' => 'dismissed']);

        $this->assertSame(['p'.$pendingPost->id, 't'.$pendingThread->id], $this->listed());
        $this->assertSame(['p'.$pendingPost->id, 't'.$pendingThread->id], $this->listed('status=pending'));
        $this->assertSame(['p'.$reviewedPost->id, 't'.$reviewedThread->id], $this->listed('status=reviewed'));
        $this->assertSame(['p'.$dismissedPost->id, 't'.$dismissedThread->id], $this->listed('status=dismissed'));
        $this->assertCount(6, $this->listed('status=all'));
    }

    #[Test]
    public function a_kind_of_report_can_be_asked_for_on_its_own(): void
    {
        $thread = $this->thread();
        $reportOfThread = $this->reportOfThread($thread, '2026-03-01 10:00:00');
        $reportOfPost = $this->reportOfPost($this->reply($thread), '2026-03-01 11:00:00');

        $this->assertSame(['t'.$reportOfThread->id], $this->listed('type=thread'));
        $this->assertSame(['p'.$reportOfPost->id], $this->listed('type=post'));
        $this->assertSame(['p'.$reportOfPost->id, 't'.$reportOfThread->id], $this->listed('type=all'));

        // The pages count only what was asked for.
        $this->actingAs($this->moderator())
            ->get(route('acp.forums.reports.index', ['type' => 'post']))
            ->assertInertia(fn (Assert $page) => $page->where('reports.meta.total', 1));
    }

    #[Test]
    public function the_reason_the_board_and_the_title_narrow_the_list_of_both_kinds(): void
    {
        $news = $this->board('News');
        $help = $this->board('Help');

        $newsThread = $this->thread($news, 'Server restart tonight');
        $helpThread = $this->thread($help, 'How do I reset my password');

        $spamOfNews = $this->reportOfThread($newsThread, '2026-03-01 10:00:00');
        $abuseOfNews = $this->reportOfThread($newsThread, '2026-03-01 11:00:00', ['reason_category' => 'abuse']);
        $spamOfHelp = $this->reportOfThread($helpThread, '2026-03-01 12:00:00');
        $postSpamOfNews = $this->reportOfPost($this->reply($newsThread), '2026-03-01 13:00:00');
        $postAbuseOfNews = $this->reportOfPost($this->reply($newsThread), '2026-03-01 14:00:00', ['reason_category' => 'abuse']);
        $postSpamOfHelp = $this->reportOfPost($this->reply($helpThread), '2026-03-01 15:00:00');

        // The reason.
        $this->assertSame(['p'.$postAbuseOfNews->id, 't'.$abuseOfNews->id], $this->listed('reason_category=abuse'));

        // The board of the thread, or of the thread the post is in.
        $this->assertSame(
            ['p'.$postAbuseOfNews->id, 'p'.$postSpamOfNews->id, 't'.$abuseOfNews->id, 't'.$spamOfNews->id],
            $this->listed('board_id='.$news->id),
        );
        $this->assertSame(['p'.$postSpamOfHelp->id, 't'.$spamOfHelp->id], $this->listed('board_id='.$help->id));

        // A part of the title of the thread, or of the thread the post is in.
        $this->assertSame(
            ['p'.$postAbuseOfNews->id, 'p'.$postSpamOfNews->id, 't'.$abuseOfNews->id, 't'.$spamOfNews->id],
            $this->listed('search=restart'),
        );
        $this->assertSame(['p'.$postSpamOfHelp->id, 't'.$spamOfHelp->id], $this->listed('search=password'));
        $this->assertSame([], $this->listed('search=nothing-has-this-title'));

        // All of them together.
        $this->assertSame(['t'.$abuseOfNews->id], $this->listed('type=thread&reason_category=abuse&board_id='.$news->id.'&search=server'));
    }

    #[Test]
    public function a_report_of_a_post_that_was_deleted_stays_in_the_list_with_what_the_post_said(): void
    {
        $thread = $this->thread();
        $kept = $this->reply($thread);
        $deleted = $this->reply($thread, '<p>Said and then deleted</p>');

        $reportOfKept = $this->reportOfPost($kept, '2026-03-01 10:00:00');
        $reportOfDeleted = $this->reportOfPost($deleted, '2026-03-01 11:00:00');

        // Deleting what was reported is one of the things a moderator does about a report, and the report is still there afterwards.
        $deleted->delete();

        $this->assertSame(['p'.$reportOfDeleted->id, 'p'.$reportOfKept->id], $this->listed());

        $this->actingAs($this->moderator())
            ->get(route('acp.forums.reports.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('reports.meta.total', 2)
                ->where('reports.data.0.post.body_preview', 'Said and then deleted'));
    }

    #[Test]
    public function a_row_has_what_the_page_shows_of_a_thread_report_and_of_a_post_report(): void
    {
        Carbon::setTestNow('2026-03-02 09:00:00');

        $board = $this->board('News');
        $threadAuthor = User::factory()->create(['nickname' => 'thread-author', 'email' => 'thread-author@example.test']);
        $postAuthor = User::factory()->create(['nickname' => 'post-author', 'email' => 'post-author@example.test']);
        $reporter = User::factory()->create(['nickname' => 'the-reporter', 'email' => 'reporter@example.test']);
        $reviewer = User::factory()->create(['nickname' => 'the-reviewer', 'email' => 'reviewer@example.test']);

        $thread = $this->thread($board, 'Server restart', $threadAuthor);
        $thread->forceFill(['is_locked' => true])->save();
        $post = $this->reply($thread, '<p>Buy <b>cheap</b> watches</p>', $postAuthor);

        $reportOfThread = $this->reportOfThread($thread, '2026-03-01 10:00:00', [
            'reporter_id' => $reporter->id,
            'reason_category' => 'abuse',
            'reason' => 'Rude.',
            'evidence_url' => 'https://example.test/evidence',
            'status' => 'reviewed',
            'reviewed_at' => '2026-03-01 12:00:00',
            'reviewed_by' => $reviewer->id,
        ]);
        $reportOfPost = $this->reportOfPost($post, '2026-03-01 09:00:00', ['reporter_id' => $reporter->id]);

        $threadSummary = ['id' => $thread->id, 'title' => 'Server restart', 'slug' => $thread->slug, 'is_locked' => true, 'is_published' => true, 'board' => ['id' => $board->id, 'title' => 'News', 'slug' => 'news']];
        $person = fn (User $user) => ['id' => $user->id, 'nickname' => $user->nickname, 'email' => $user->email];

        $this->actingAs($this->moderator())
            ->get(route('acp.forums.reports.index', ['status' => 'all']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('reports.data.0', [
                    'id' => $reportOfThread->id,
                    'type' => 'thread',
                    'moderate_url' => null,
                    'status' => 'reviewed',
                    'reason_category' => 'abuse',
                    'reason' => 'Rude.',
                    'evidence_url' => 'https://example.test/evidence',
                    'created_at' => '2026-03-01T10:00:00+00:00',
                    'reviewed_at' => '2026-03-01T12:00:00+00:00',
                    'reporter' => $person($reporter),
                    'reviewer' => $person($reviewer),
                    'thread' => $threadSummary,
                ])
                ->where('reports.data.1', [
                    'id' => $reportOfPost->id,
                    'type' => 'post',
                    'moderate_url' => null,
                    'status' => 'pending',
                    'reason_category' => 'spam',
                    'reason' => 'Looks like spam.',
                    'evidence_url' => null,
                    'created_at' => '2026-03-01T09:00:00+00:00',
                    'reviewed_at' => null,
                    'reporter' => $person($reporter),
                    'reviewer' => null,
                    'post' => ['id' => $post->id, 'body_preview' => 'Buy cheap watches', 'author' => $person($postAuthor)],
                    'thread' => $threadSummary,
                ]));
    }

    #[Test]
    public function a_moderator_who_may_warn_is_offered_the_page_for_the_author_of_a_thread_and_of_a_post_but_not_for_themselves(): void
    {
        $moderator = $this->moderator();
        $moderator->givePermissionTo(Permission::findOrCreate('users.acp.warn'));

        $threadAuthor = User::factory()->create();
        $postAuthor = User::factory()->create();
        $thread = $this->thread(null, 'Reported', $threadAuthor);
        $post = $this->reply($thread, '<p>Reported too</p>', $postAuthor);
        $ownThread = $this->thread(null, 'Their own', $moderator);

        $reportOfThread = $this->reportOfThread($thread, '2026-03-01 10:00:00');
        $reportOfPost = $this->reportOfPost($post, '2026-03-01 11:00:00');
        $reportOfOwnThread = $this->reportOfThread($ownThread, '2026-03-01 12:00:00');

        $this->actingAs($moderator)
            ->get(route('acp.forums.reports.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('reports.data', function ($data) use ($reportOfThread, $reportOfPost, $reportOfOwnThread, $threadAuthor, $postAuthor, $thread, $post) {
                $urls = collect($data)->mapWithKeys(fn (array $row) => [$row['type'][0].$row['id'] => $row['moderate_url']])->all();

                $this->assertSame([
                    't'.$reportOfOwnThread->id => null,
                    'p'.$reportOfPost->id => route('acp.users.moderation.show', ['user' => $postAuthor->id, 'subject' => 'forum_post:'.$post->id]),
                    't'.$reportOfThread->id => route('acp.users.moderation.show', ['user' => $threadAuthor->id, 'subject' => 'forum_thread:'.$thread->id]),
                ], $urls);

                return true;
            }));
    }

    #[Test]
    public function the_counts_by_status_are_those_of_every_report_whatever_the_list_is_narrowed_to(): void
    {
        $thread = $this->thread();
        $this->reportOfThread($thread, '2026-03-01 10:00:00');
        $this->reportOfThread($this->thread(), '2026-03-01 11:00:00', ['status' => 'reviewed']);
        $this->reportOfPost($this->reply($thread), '2026-03-01 12:00:00');
        $this->reportOfPost($this->reply($thread), '2026-03-01 13:00:00');
        $this->reportOfPost($this->reply($thread), '2026-03-01 14:00:00', ['status' => 'dismissed']);

        $this->actingAs($this->moderator())
            ->get(route('acp.forums.reports.index', ['type' => 'thread', 'status' => 'reviewed']))
            ->assertInertia(fn (Assert $page) => $page
                ->where('statusSummary.pending', ['threads' => 1, 'posts' => 2, 'total' => 3])
                ->where('statusSummary.reviewed', ['threads' => 1, 'posts' => 0, 'total' => 1])
                ->where('statusSummary.dismissed', ['threads' => 0, 'posts' => 1, 'total' => 1]));
    }

    #[Test]
    public function the_list_is_for_staff_who_may_see_the_forums(): void
    {
        $this->get(route('acp.forums.reports.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())->get(route('acp.forums.reports.index'))->assertForbidden();
    }
}
