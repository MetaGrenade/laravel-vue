<?php

namespace Tests\Feature\Forum;

use App\Models\ForumAttachment;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Support\Forum\ForumAttachments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

/**
 * `php artisan metaforger:forum:attachments:prune`: the files in the attachment directory that no attachment names, which a board
 * deleted before its files were deleted with it left behind (docs/forum-attachments.md).
 */
class PruneForumAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function aReply(): ForumPost
    {
        $author = User::factory()->create();
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general'.++$this->sequence, 'description' => 'x', 'position' => 1]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'Board', 'slug' => 'board'.$this->sequence, 'description' => 'x', 'position' => 1]);
        $thread = ForumThread::create([
            'forum_board_id' => $board->id, 'user_id' => $author->id, 'title' => 'Thread', 'slug' => 'thread'.$this->sequence, 'excerpt' => null,
            'is_locked' => false, 'is_pinned' => false, 'is_published' => true, 'views' => 0, 'last_posted_at' => now(), 'last_post_user_id' => $author->id,
        ]);

        return ForumPost::query()->create(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => '<p>Reply</p>']);
    }

    /**
     * A path as the class writes it: the post, 26 characters, an extension.
     */
    private function path(int $post = 12, string $extension = 'txt'): string
    {
        return sprintf('forum-attachments/%d/%026d.%s', $post, ++$this->sequence, $extension);
    }

    /**
     * A file on the disk, two hours old unless said otherwise.
     */
    private function stored(string $path, string $disk = 'local', int $age = 7200): string
    {
        Storage::disk($disk)->put($path, 'a file');
        touch(Storage::disk($disk)->path($path), time() - $age);

        return $path;
    }

    private function named(string $path, string $disk = 'local'): ForumAttachment
    {
        $post = $this->aReply();

        return ForumAttachment::query()->create(['forum_post_id' => $post->id, 'user_id' => $post->user_id, 'disk' => $disk, 'path' => $path, 'name' => 'note.txt', 'mime_type' => 'text/plain', 'size' => 6]);
    }

    /**
     * @param  list<string>  $paths
     */
    private function assertGone(array $paths, string $disk = 'local'): void
    {
        foreach ($paths as $path) {
            Storage::disk($disk)->assertMissing($path);
        }
    }

    /**
     * @param  list<string>  $paths
     */
    private function assertKept(array $paths, string $disk = 'local'): void
    {
        foreach ($paths as $path) {
            Storage::disk($disk)->assertExists($path);
        }
    }

    #[Test]
    public function the_files_a_board_deleted_the_old_way_left_are_deleted_and_the_files_that_are_named_stay(): void
    {
        $category = ForumCategory::create(['title' => 'Gone', 'slug' => 'gone', 'description' => 'x', 'position' => 9]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'Gone', 'slug' => 'gone-board', 'description' => 'x', 'position' => 9]);
        $author = User::factory()->create();
        $thread = ForumThread::create([
            'forum_board_id' => $board->id, 'user_id' => $author->id, 'title' => 'Gone', 'slug' => 'gone-thread', 'excerpt' => null,
            'is_locked' => false, 'is_pinned' => false, 'is_published' => true, 'views' => 0, 'last_posted_at' => now(), 'last_post_user_id' => $author->id,
        ]);
        $reply = ForumPost::query()->create(['forum_thread_id' => $thread->id, 'user_id' => $author->id, 'body' => '<p>Gone</p>']);

        $left = [$this->stored($this->path($reply->id)), $this->stored($this->path($reply->id, 'webp')), $this->stored($this->path($reply->id, 'pdf'))];

        foreach ($left as $path) {
            ForumAttachment::query()->create(['forum_post_id' => $reply->id, 'user_id' => $author->id, 'disk' => 'local', 'path' => $path, 'name' => 'x', 'mime_type' => 'text/plain', 'size' => 6]);
        }

        // What the board did before it deleted its files: the rows went with it, and the files stayed.
        ForumBoard::withoutEvents(fn () => $board->delete());
        $this->assertSame(0, ForumAttachment::query()->count());
        $this->assertKept($left);

        $named = $this->stored($this->path());
        $this->named($named);

        $this->artisan('metaforger:forum:attachments:prune')->expectsOutputToContain('3 file(s)')->assertSuccessful();

        $this->assertGone($left);
        $this->assertKept([$named]);
    }

    #[Test]
    public function what_is_young_what_is_elsewhere_and_what_is_not_named_like_an_attachment_stays(): void
    {
        $young = $this->stored($this->path(), age: 59 * 60);
        $others = [
            $this->stored('products/12/'.str_repeat('A', 26).'.txt'),
            $this->stored('forum-attachments/.gitignore'),
            $this->stored('forum-attachments/notes.txt'),
            $this->stored('forum-attachments/12/notes.txt'),
            $this->stored('forum-attachments/abc/'.str_repeat('A', 26).'.txt'),
            $this->stored('forum-attachments/12/'.str_repeat('A', 25).'.txt'),
            $this->stored('forum-attachments/12/'.str_repeat('A', 27).'.txt'),
            $this->stored('forum-attachments/12/'.str_repeat('A', 26).'.exe'),
            $this->stored('forum-attachments/12/'.str_repeat('A', 26).'.TXT'),
            $this->stored('forum-attachments/12/sub/'.str_repeat('A', 26).'.txt'),
            $this->stored('forum-attachments-2/12/'.str_repeat('A', 26).'.txt'),
        ];

        $this->artisan('metaforger:forum:attachments:prune')->expectsOutputToContain('0 file(s)')->assertSuccessful();

        $this->assertKept([$young, ...$others]);
    }

    #[Test]
    public function a_file_is_taken_once_it_is_an_hour_old(): void
    {
        $edge = $this->stored($this->path(), age: 61 * 60);

        $this->artisan('metaforger:forum:attachments:prune')->assertSuccessful();

        $this->assertGone([$edge]);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function paths(): array
    {
        $id = str_repeat('0', 26);

        return [
            'a text file' => ["forum-attachments/12/$id.txt", true],
            'a picture' => ["forum-attachments/12/$id.webp", true],
            'a pdf' => ["forum-attachments/12/$id.pdf", true],
            'a zip' => ["forum-attachments/12/$id.zip", true],
            'letters of both cases' => ['forum-attachments/7/01ARZ3NDEKTSV4RRFFQ69G5FAV.txt', true],
            'a new line after the extension' => ["forum-attachments/12/$id.txt\n", false],
            'no post' => ["forum-attachments/$id.txt", false],
            'a post that is not a number' => ["forum-attachments/x/$id.txt", false],
            'a post that is not there' => ["forum-attachments//$id.txt", false],
            'the directory somewhere inside the path' => ["uploads/forum-attachments/12/$id.txt", false],
            'a name of 25 characters' => ['forum-attachments/12/'.str_repeat('0', 25).'.txt', false],
            'a name of 27 characters' => ['forum-attachments/12/'.str_repeat('0', 27).'.txt', false],
            'an extension that is not one of ours' => ["forum-attachments/12/$id.php", false],
            'another directory' => ["uploads/12/$id.txt", false],
            'the directory as a prefix of another' => ["forum-attachments-old/12/$id.txt", false],
            'a directory in between' => ["forum-attachments/12/a/$id.txt", false],
        ];
    }

    #[Test]
    #[DataProvider('paths')]
    public function a_path_is_an_attachment_path_only_in_the_shape_the_class_writes(string $path, bool $expected): void
    {
        $this->assertSame($expected, app(ForumAttachments::class)->isAttachmentPath($path));
    }

    #[Test]
    public function the_directory_is_a_name_and_not_a_pattern(): void
    {
        config(['forum.attachments.directory' => 'uploads.v2']);

        $id = str_repeat('0', 26);

        $this->assertTrue(app(ForumAttachments::class)->isAttachmentPath("uploads.v2/12/$id.txt"));
        $this->assertFalse(app(ForumAttachments::class)->isAttachmentPath("uploadsXv2/12/$id.txt"), 'a dot in the name is a dot');
    }

    #[Test]
    public function the_command_says_how_much_there_is(): void
    {
        $path = $this->path();
        Storage::disk('local')->put($path, str_repeat('x', 2048));
        touch(Storage::disk('local')->path($path), time() - 7200);

        $this->artisan('metaforger:forum:attachments:prune', ['--dry-run' => true])->expectsOutputToContain('2.0 kB')->assertSuccessful();
        $this->artisan('metaforger:forum:attachments:prune')->expectsOutputToContain('2.0 kB')->assertSuccessful();
    }

    #[Test]
    public function a_file_named_by_a_row_stays_whatever_disk_the_row_says(): void
    {
        // The two names may be one directory on the machine: the row decides, not the name of the disk.
        $path = $this->stored($this->path());
        $this->named($path, disk: 'private');

        $this->artisan('metaforger:forum:attachments:prune')->expectsOutputToContain('0 file(s)')->assertSuccessful();

        $this->assertKept([$path]);
    }

    #[Test]
    public function more_files_than_one_batch_are_all_looked_at(): void
    {
        // The names are looked up 500 at a time: a file in the second batch that a row names must be spared too.
        $paths = [];

        foreach (range(1, 520) as $n) {
            $paths[] = $this->stored($this->path());
        }

        $this->named($paths[510]);

        $this->artisan('metaforger:forum:attachments:prune')->expectsOutputToContain('519 file(s)')->assertSuccessful();

        $this->assertKept([$paths[510]]);
        $this->assertGone(array_values(array_diff($paths, [$paths[510]])));
    }

    #[Test]
    public function it_looks_in_the_directory_and_on_the_disk_that_are_set(): void
    {
        Storage::fake('archive');
        config(['forum.attachments.disk' => 'archive', 'forum.attachments.directory' => '/uploads/forum/']);

        $stray = $this->stored('uploads/forum/12/'.str_repeat('B', 26).'.txt', 'archive');
        $notOurs = $this->stored($this->path());

        $this->artisan('metaforger:forum:attachments:prune')->expectsOutputToContain('1 file(s)')->assertSuccessful();

        $this->assertGone([$stray], 'archive');
        $this->assertKept([$notOurs]);
    }

    #[Test]
    public function a_dry_run_says_what_it_would_delete_and_deletes_nothing(): void
    {
        $stray = $this->stored($this->path());

        $this->artisan('metaforger:forum:attachments:prune', ['--dry-run' => true])
            ->expectsOutputToContain("would delete $stray")
            ->expectsOutputToContain('1 file(s)')
            ->assertSuccessful();

        $this->assertKept([$stray]);

        $this->artisan('metaforger:forum:attachments:prune')->expectsOutputToContain('1 file(s)')->assertSuccessful();
        $this->artisan('metaforger:forum:attachments:prune')->expectsOutputToContain('0 file(s)')->assertSuccessful();
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function waysADiskRefuses(): array
    {
        return [
            'it says false, as the disks do' => [false],
            'it throws, as a disk set to throw does' => [true],
        ];
    }

    #[Test]
    #[DataProvider('waysADiskRefuses')]
    public function a_file_that_could_not_be_deleted_is_not_counted_and_the_command_says_so(bool $throws): void
    {
        $stuck = $this->stored($this->path());
        $fine = [$this->stored($this->path()), $this->stored($this->path())];

        $real = Storage::disk('local');
        $disk = Mockery::mock($real);
        $disk->shouldReceive('delete')->andReturnUsing(function ($path) use ($real, $stuck, $throws) {
            if ($path !== $stuck) {
                return $real->delete($path);
            }

            return $throws ? throw new RuntimeException('Permission denied.') : false;
        });
        Storage::set('local', $disk);

        $this->artisan('metaforger:forum:attachments:prune')
            ->expectsOutputToContain($stuck.': '.($throws ? 'Permission denied.' : 'could not be deleted.'))
            ->expectsOutputToContain('2 file(s)')
            ->expectsOutputToContain('1 file(s) could not be deleted and are still there')
            ->assertFailed();

        $real->assertExists($stuck);
        $this->assertGone($fine);
    }
}
