<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\Forum\ThreadSolutions;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One reply marked as the answer on a thread (docs/forum-solutions.md).
 */
class ForumSolutionTest extends TestCase
{
    use RefreshDatabase;

    private ForumCategory $category;

    private ForumBoard $board;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->category = ForumCategory::create([
            'title' => 'General',
            'slug' => 'general',
            'description' => 'Chat',
            'position' => 1,
        ]);

        $this->board = ForumBoard::create([
            'forum_category_id' => $this->category->id,
            'title' => 'Help',
            'slug' => 'help-board',
            'description' => 'Questions',
            'position' => 1,
        ]);
    }

    public function test_the_author_marks_a_reply_and_the_pages_show_it(): void
    {
        $author = User::factory()->create();
        $thread = $this->thread($author, 'How do I plant a tree');
        $start = Carbon::parse('2026-04-01 12:00:00');
        $opener = $this->reply($thread, $author, 'The question', $start);

        for ($i = 0; $i < 9; $i++) {
            $this->reply($thread, $author, 'Filler '.$i, $start->copy()->addMinutes($i + 1));
        }

        $reply = $this->reply($thread, $author, 'Dig a hole first.', $start->copy()->addMinutes(30));

        $this->actingAs($author)
            ->from(route('forum.threads.show', [$this->board, $thread]))
            ->put(route('forum.threads.solution', [$this->board, $thread]), [
                'post_id' => $reply->id,
            ])
            ->assertRedirect(route('forum.threads.show', [$this->board, $thread]))
            ->assertSessionHas('success', 'Reply marked as the solution.');

        $this->assertSame($reply->id, $thread->fresh()->solved_post_id);

        $this->actingAs($author)
            ->get(route('forum.threads.show', [$this->board, $thread]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('thread.solution.post_id', $reply->id)
                ->where('thread.solution.page', 2)
                ->where('thread.opener_post_id', $opener->id)
                ->where('thread.permissions.canMarkSolution', true)
            );

        auth()->logout();

        $this->get(route('forum.threads.show', [$this->board, $thread]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('thread.solution.post_id', $reply->id)
                ->where('thread.permissions.canMarkSolution', false)
            );

        $this->get(route('forum.boards.show', $this->board))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('threads.data.0.solved', true)
                ->where('filters.solved', null)
            );

        $this->get(route('forum.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('categories.0.boards.0.latest_thread.solved', true)
                ->where('trendingThreads.0.solved', true)
                ->where('latestPosts.0.solved', true)
            );
    }

    public function test_the_opening_post_a_held_reply_and_another_thread_are_refused(): void
    {
        $author = User::factory()->create();
        $thread = $this->thread($author, 'How do I plant a tree');
        $opener = $this->reply($thread, $author, 'The question');
        $held = $this->reply($thread, $author, 'Hidden');
        $held->forceFill(['held_at' => now(), 'held_reason' => 'spam'])->save();

        $other = $this->thread($author, 'Something else');
        $foreign = $this->reply($other, $author, 'Not this thread');

        $this->actingAs($author)
            ->from(route('forum.threads.show', [$this->board, $thread]))
            ->put(route('forum.threads.solution', [$this->board, $thread]), [
                'post_id' => $opener->id,
            ])
            ->assertInvalid(['post_id' => 'The opening post cannot be the solution.']);

        $this->actingAs($author)
            ->from(route('forum.threads.show', [$this->board, $thread]))
            ->put(route('forum.threads.solution', [$this->board, $thread]), [
                'post_id' => $held->id,
            ])
            ->assertInvalid(['post_id' => 'That reply is not in this thread.']);

        $this->actingAs($author)
            ->from(route('forum.threads.show', [$this->board, $thread]))
            ->put(route('forum.threads.solution', [$this->board, $thread]), [
                'post_id' => $foreign->id,
            ])
            ->assertInvalid(['post_id' => 'That reply is not in this thread.']);

        $this->assertNull($thread->fresh()->solved_post_id);
    }

    public function test_a_stranger_cannot_mark_one_and_a_moderator_can_clear_it(): void
    {
        $author = User::factory()->create();
        $thread = $this->thread($author, 'How do I plant a tree');
        $this->reply($thread, $author, 'The question');
        $reply = $this->reply($thread, $author, 'Dig a hole first.');

        $this->actingAs(User::factory()->create())
            ->put(route('forum.threads.solution', [$this->board, $thread]), [
                'post_id' => $reply->id,
            ])
            ->assertForbidden();

        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $this->actingAs($moderator)
            ->put(route('forum.threads.solution', [$this->board, $thread]), [
                'post_id' => $reply->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Reply marked as the solution.');

        $this->actingAs($moderator)
            ->put(route('forum.threads.solution', [$this->board, $thread]), [
                'post_id' => null,
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Solution cleared.');

        $this->assertNull($thread->fresh()->solved_post_id);
    }

    public function test_a_locked_thread_can_be_solved_and_an_unpublished_one_cannot(): void
    {
        $author = User::factory()->create();
        $thread = $this->thread($author, 'How do I plant a tree');
        $this->reply($thread, $author, 'The question');
        $reply = $this->reply($thread, $author, 'Dig a hole first.');

        $thread->forceFill(['is_locked' => true])->save();

        $this->actingAs($author)
            ->put(route('forum.threads.solution', [$this->board, $thread]), [
                'post_id' => $reply->id,
            ])
            ->assertRedirect();

        $this->assertSame($reply->id, $thread->fresh()->solved_post_id);

        $thread->forceFill(['is_published' => false, 'solved_post_id' => null])->save();

        $this->actingAs($author)
            ->put(route('forum.threads.solution', [$this->board, $thread]), [
                'post_id' => $reply->id,
            ])
            ->assertForbidden();

        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $this->actingAs($moderator)
            ->put(route('forum.threads.solution', [$this->board, $thread]), [
                'post_id' => $reply->id,
            ])
            ->assertForbidden();

        $this->assertNull($thread->fresh()->solved_post_id);
    }

    public function test_deleting_the_reply_clears_the_solution(): void
    {
        $author = User::factory()->create();
        $thread = $this->thread($author, 'How do I plant a tree');
        $this->reply($thread, $author, 'The question');
        $reply = $this->reply($thread, $author, 'Dig a hole first.');
        $thread->forceFill(['solved_post_id' => $reply->id])->save();

        $reply->delete();

        $this->assertNull($thread->fresh()->solved_post_id);

        $again = $this->reply($thread, $author, 'Another fix.');
        $thread->forceFill(['solved_post_id' => $again->id])->save();

        ForumPost::query()->whereKey($again->id)->delete();

        $this->assertSame($again->id, $thread->fresh()->solved_post_id);

        app(ThreadSolutions::class)->forget([$again->id]);

        $this->assertNull($thread->fresh()->solved_post_id);
    }

    public function test_the_board_filters_solved_and_unsolved_threads(): void
    {
        $author = User::factory()->create();
        $solved = $this->thread($author, 'Solved one');
        $open = $this->thread($author, 'Open one');
        $this->reply($solved, $author, 'The question');
        $reply = $this->reply($solved, $author, 'The fix');
        $this->reply($open, $author, 'Still asking');
        $solved->forceFill(['solved_post_id' => $reply->id])->save();

        $this->get(route('forum.boards.show', ['board' => $this->board->slug, 'solved' => 'yes']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('threads.data', 1)
                ->where('threads.data.0.slug', 'solved-one')
                ->where('threads.data.0.solved', true)
                ->where('filters.solved', 'yes')
            );

        $this->get(route('forum.boards.show', ['board' => $this->board->slug, 'solved' => 'no']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('threads.data', 1)
                ->where('threads.data.0.slug', 'open-one')
                ->where('threads.data.0.solved', false)
                ->where('filters.solved', 'no')
            );

        $this->get(route('forum.boards.show', ['board' => $this->board->slug, 'solved' => 'maybe']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('threads.data', 2)
                ->where('filters.solved', null)
            );
    }

    public function test_the_api_sets_and_clears_the_solution(): void
    {
        $author = User::factory()->create();
        $stranger = User::factory()->create();
        $thread = $this->thread($author, 'How do I plant a tree');
        $opener = $this->reply($thread, $author, 'The question');
        $reply = $this->reply($thread, $author, 'Dig a hole first.');

        Sanctum::actingAs($author, ['*']);

        $this->putJson(route('api.v1.forum.threads.solution', [$this->board, $thread]), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('post_id');

        $this->putJson(route('api.v1.forum.threads.solution', [$this->board, $thread]), [
            'post_id' => $opener->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('post_id');

        $this->putJson(route('api.v1.forum.threads.solution', [$this->board, $thread]), [
            'post_id' => $reply->id,
        ])->assertOk()
            ->assertJsonPath('data.solution_post_id', $reply->id);

        $this->putJson(route('api.v1.forum.threads.solution', [$this->board, $thread]), [
            'post_id' => null,
        ])->assertOk()
            ->assertJsonPath('data.solution_post_id', null);

        Sanctum::actingAs($stranger, ['*']);

        $this->putJson(route('api.v1.forum.threads.solution', [$this->board, $thread]), [
            'post_id' => $reply->id,
        ])->assertForbidden();
    }

    private function thread(User $author, string $title): ForumThread
    {
        return ForumThread::create([
            'forum_board_id' => $this->board->id,
            'user_id' => $author->id,
            'title' => $title,
            'slug' => str($title)->slug()->toString(),
            'excerpt' => $title,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 3,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
        ]);
    }

    private function reply(ForumThread $thread, User $author, string $body, ?Carbon $at = null): ForumPost
    {
        $post = ForumPost::create([
            'forum_thread_id' => $thread->id,
            'user_id' => $author->id,
            'body' => $body,
        ]);

        if ($at !== null) {
            $post->forceFill([
                'created_at' => $at,
                'updated_at' => $at,
            ])->save();
        }

        return $post;
    }
}
