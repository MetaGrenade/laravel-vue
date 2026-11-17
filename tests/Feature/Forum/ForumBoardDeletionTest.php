<?php

namespace Tests\Feature\Forum;

use App\Models\ForumAttachment;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Deleting a forum board, or a whole category, deletes the files kept with every reply in it (docs/forum-attachments.md).
 *
 * The threads and replies go with the board by `ON DELETE CASCADE`, which runs no model events, so the hooks of a thread, a reply and an
 * attachment that unlink the files never ran: the files of a board that staff had deleted stayed on the private disk for good.
 */
class ForumBoardDeletionTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    private function category(string $slug): ForumCategory
    {
        return ForumCategory::create(['title' => $slug, 'slug' => $slug, 'description' => 'x', 'position' => ++$this->sequence]);
    }

    private function board(ForumCategory $category, string $slug): ForumBoard
    {
        return ForumBoard::create(['forum_category_id' => $category->id, 'title' => $slug, 'slug' => $slug, 'description' => 'x', 'position' => ++$this->sequence]);
    }

    private function thread(ForumBoard $board, bool $published = true): ForumThread
    {
        $author = User::factory()->create();

        return ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $author->id,
            'title' => 'A thread '.++$this->sequence,
            'slug' => 'a-thread-'.$this->sequence,
            'excerpt' => null,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => $published,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $author->id,
        ]);
    }

    private function reply(ForumThread $thread): ForumPost
    {
        return ForumPost::query()->create(['forum_thread_id' => $thread->id, 'user_id' => $thread->user_id, 'body' => '<p>Reply</p>']);
    }

    /**
     * A file on a reply: its row, and the file itself on the disk.
     *
     * @return array{0: string, 1: string} The disk and the path.
     */
    private function file(ForumPost $post, string $disk = 'local'): array
    {
        $path = "forum-attachments/{$post->id}/".++$this->sequence.'.txt';

        Storage::disk($disk)->put($path, 'a file');
        ForumAttachment::query()->create([
            'forum_post_id' => $post->id,
            'user_id' => $post->user_id,
            'disk' => $disk,
            'path' => $path,
            'name' => 'note.txt',
            'mime_type' => 'text/plain',
            'size' => 6,
        ]);

        return [$disk, $path];
    }

    /**
     * Every kind of reply a board can hold, each with a file: in a published thread, in a thread that is still held, a reply held for
     * review, and a reply that was soft-deleted with its files left (as an older version did).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function fill(ForumBoard $board): array
    {
        $published = $this->thread($board);
        $held = $this->thread($board, published: false);
        $files = [$this->file($this->reply($published)), $this->file($this->reply($published)), $this->file($this->reply($held))];

        $heldReply = $this->reply($published);
        $heldReply->forceFill(['held_at' => now()])->save();
        $files[] = $this->file($heldReply);

        $trashed = $this->reply($published);
        $files[] = $this->file($trashed);
        ForumPost::withoutEvents(fn () => $trashed->delete());

        return $files;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $files
     */
    private function assertGone(array $files): void
    {
        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->assertMissing($path);
            $this->assertDatabaseMissing('forum_attachments', ['path' => $path]);
        }
    }

    /**
     * @param  list<array{0: string, 1: string}>  $files
     */
    private function assertKept(array $files): void
    {
        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->assertExists($path);
            $this->assertDatabaseHas('forum_attachments', ['path' => $path]);
        }
    }

    #[Test]
    public function the_files_of_a_deleted_board_go_with_it_and_those_of_the_next_board_stay(): void
    {
        $category = $this->category('general');
        $gone = $this->board($category, 'announcements');
        $kept = $this->board($category, 'chat');

        $mine = $this->fill($gone);
        $theirs = $this->fill($kept);

        $this->actingAs($this->admin())
            ->delete(route('acp.forums.boards.destroy', $gone))
            ->assertRedirect(route('acp.forums.index'))
            ->assertSessionHas('success', 'Forum board deleted successfully.');

        $this->assertDatabaseMissing('forum_boards', ['id' => $gone->id]);
        $this->assertDatabaseCount('forum_posts', 5); // the next board keeps its own replies
        $this->assertGone($mine);
        $this->assertKept($theirs);
    }

    #[Test]
    public function the_files_of_every_board_of_a_deleted_category_go_and_those_of_another_category_stay(): void
    {
        $general = $this->category('general');
        $shop = $this->category('shop');

        $mine = [...$this->fill($this->board($general, 'announcements')), ...$this->fill($this->board($general, 'chat'))];
        $theirs = $this->fill($this->board($shop, 'sales'));

        $this->actingAs($this->admin())
            ->delete(route('acp.forums.categories.destroy', $general))
            ->assertRedirect(route('acp.forums.index'))
            ->assertSessionHas('success', 'Forum category deleted successfully.');

        $this->assertDatabaseMissing('forum_categories', ['id' => $general->id]);
        $this->assertDatabaseCount('forum_boards', 1);
        $this->assertGone($mine);
        $this->assertKept($theirs);
    }

    #[Test]
    public function files_on_more_than_one_disk_and_more_than_one_batch_are_all_deleted(): void
    {
        Storage::fake('archive');

        $category = $this->category('general');
        $board = $this->board($category, 'announcements');
        $thread = $this->thread($board);
        $reply = $this->reply($thread);

        $files = [];

        // 450 is more than two batches of 200, and the ones on the other disk are not found by looking at the first disk only.
        foreach (range(1, 450) as $n) {
            $files[] = $this->file($reply, $n % 3 === 0 ? 'archive' : 'local');
        }

        $board->delete();

        $this->assertSame(0, ForumAttachment::query()->count());
        $this->assertGone($files);
    }

    #[Test]
    public function a_file_the_disk_would_not_delete_is_reported_and_the_rest_carries_on(): void
    {
        // The disks are set not to throw, so a refusal is `false`, which nothing said.
        Exceptions::fake();

        $board = $this->board($this->category('general'), 'announcements');
        $files = $this->fill($board);
        $stuck = $files[1][1];

        $real = Storage::disk('local');
        $disk = Mockery::mock($real);
        $disk->shouldReceive('delete')->andReturnUsing(fn ($path) => $path === $stuck ? false : $real->delete($path));
        Storage::set('local', $disk);

        $board->delete();

        $this->assertDatabaseMissing('forum_boards', ['id' => $board->id]);
        Exceptions::assertReported(fn (RuntimeException $exception) => str_contains($exception->getMessage(), $stuck));

        foreach ($files as [, $path]) {
            if ($path !== $stuck) {
                $real->assertMissing($path);
            }
        }
        $real->assertExists($stuck);
    }

    #[Test]
    public function a_file_on_a_disk_the_site_does_not_have_is_reported_and_does_not_stop_the_deletion(): void
    {
        Exceptions::fake();

        $board = $this->board($this->category('general'), 'announcements');
        $files = $this->fill($board);

        $reply = ForumPost::query()->withoutGlobalScopes()->where('forum_thread_id', ForumThread::query()->where('forum_board_id', $board->id)->value('id'))->firstOrFail();
        ForumAttachment::query()->create(['forum_post_id' => $reply->id, 'user_id' => $reply->user_id, 'disk' => 'nowhere', 'path' => 'x/y.txt', 'name' => 'y.txt', 'mime_type' => 'text/plain', 'size' => 1]);

        $board->delete();

        $this->assertDatabaseMissing('forum_boards', ['id' => $board->id]);
        Exceptions::assertReported(InvalidArgumentException::class);
        $this->assertGone($files);
    }

    #[Test]
    public function removing_one_file_that_the_disk_would_not_delete_is_reported(): void
    {
        Exceptions::fake();

        $post = $this->reply($this->thread($this->board($this->category('general'), 'announcements')));
        [, $path] = $this->file($post);
        $attachment = ForumAttachment::query()->firstOrFail();

        $disk = Mockery::mock(Storage::disk('local'));
        $disk->shouldReceive('delete')->with($path)->andReturn(false);
        Storage::set('local', $disk);

        $attachment->delete();

        $this->assertDatabaseMissing('forum_attachments', ['id' => $attachment->id]);
        Exceptions::assertReported(fn (RuntimeException $exception) => str_contains($exception->getMessage(), $path));
    }

    #[Test]
    public function a_deletion_that_is_called_off_leaves_no_row_that_names_a_file_that_is_gone(): void
    {
        $board = $this->board($this->category('general'), 'announcements');
        $files = $this->fill($board);

        // Something that listens after the board's own hook and says no.
        ForumBoard::deleting(fn () => false);

        $this->assertFalse($board->delete());

        $this->assertDatabaseHas('forum_boards', ['id' => $board->id]);
        $this->assertGone($files);
    }

    #[Test]
    public function a_row_with_no_path_is_deleted_without_asking_a_disk_to_delete_nothing(): void
    {
        Exceptions::fake();

        $post = $this->reply($this->thread($this->board($this->category('general'), 'announcements')));
        $attachment = ForumAttachment::query()->create(['forum_post_id' => $post->id, 'user_id' => $post->user_id, 'disk' => 'local', 'path' => '', 'name' => 'x.txt', 'mime_type' => 'text/plain', 'size' => 1]);

        $attachment->delete();

        $this->assertDatabaseMissing('forum_attachments', ['id' => $attachment->id]);
        Exceptions::assertNothingReported();
    }

    #[Test]
    public function a_board_with_nothing_attached_is_deleted_as_before(): void
    {
        $board = $this->board($this->category('general'), 'announcements');
        $this->reply($this->thread($board));

        $this->actingAs($this->admin())->delete(route('acp.forums.boards.destroy', $board))->assertRedirect();

        $this->assertDatabaseMissing('forum_boards', ['id' => $board->id]);
        $this->assertSame(0, DB::table('forum_posts')->count());
    }
}
