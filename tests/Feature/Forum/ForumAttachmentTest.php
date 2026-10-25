<?php

namespace Tests\Feature\Forum;

use App\Models\AutomodRule;
use App\Models\ForumAttachment;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Notifications\ForumPostMentioned;
use App\Support\Moderation\MemberContent;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use League\Flysystem\Config;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;
use Tests\TestCase;

/**
 * Pictures and files kept with a forum post (docs/forum-attachments.md).
 */
class ForumAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private ForumBoard $board;

    private ForumThread $thread;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Storage::fake('local');

        $category = ForumCategory::create([
            'title' => 'General',
            'slug' => 'general',
            'description' => 'Chat',
            'position' => 1,
        ]);

        $this->board = ForumBoard::create([
            'forum_category_id' => $category->id,
            'title' => 'Announcements',
            'slug' => 'announcements',
            'description' => 'News',
            'position' => 1,
        ]);

        $this->author = User::factory()->create();

        $this->thread = ForumThread::create([
            'forum_board_id' => $this->board->id,
            'user_id' => $this->author->id,
            'title' => 'Thread title',
            'slug' => 'thread-title',
            'excerpt' => null,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $this->author->id,
        ]);
    }

    public function test_a_picture_is_shown_and_served_as_webp_and_a_pdf_is_downloaded(): void
    {
        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>See the files</p>',
            'attachments' => [$this->png('photo.png'), $this->pdf()],
        ])->assertRedirect();

        $picture = ForumAttachment::query()->where('mime_type', 'image/webp')->first();
        $document = ForumAttachment::query()->where('mime_type', 'application/pdf')->first();
        $this->assertNotNull($picture);
        $this->assertNotNull($document);
        $this->assertSame('photo.webp', $picture->name);
        $this->assertSame('notes.pdf', $document->name);
        Storage::disk('local')->assertExists($picture->path);

        $this->get(route('forum.threads.show', [$this->board, $this->thread]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('posts.data.0.attachments.0.image', true)
                ->where('posts.data.0.attachments.0.name', 'photo.webp')
                ->where('posts.data.0.attachments.1.image', false)
                ->where('posts.data.0.attachments.1.name', 'notes.pdf'));

        auth()->logout();

        $image = $this->get(route('forum.attachments.show', $picture));
        $image->assertOk();
        $this->assertStringContainsString('image/webp', (string) $image->headers->get('content-type'));
        $this->assertStringContainsString('WEBP', $image->streamedContent());

        $download = $this->get(route('forum.attachments.show', $document));
        $download->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $download->headers->get('content-type'));
        $this->assertStringContainsString('attachment', (string) $download->headers->get('content-disposition'));
    }

    public function test_a_document_that_claims_to_be_a_picture_is_refused(): void
    {
        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>Hello</p>',
            'attachments' => [UploadedFile::fake()->createWithContent('photo.png', '<?php echo 1;')],
        ])->assertSessionHasErrors('attachments.0');

        $this->assertDatabaseCount('forum_attachments', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, ForumPost::query()->count());
    }

    public function test_too_many_files_and_a_file_over_the_size_are_refused(): void
    {
        $files = [];

        for ($i = 0; $i < 6; $i++) {
            $files[] = UploadedFile::fake()->createWithContent('note-'.$i.'.txt', 'hello');
        }

        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>Hello</p>',
            'attachments' => $files,
        ])->assertSessionHasErrors('attachments');

        config(['forum.attachments.max_kilobytes' => 1]);

        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>Hello</p>',
            'attachments' => [UploadedFile::fake()->createWithContent('long.txt', str_repeat('a', 2048))],
        ])->assertSessionHasErrors('attachments.0');

        $this->assertDatabaseCount('forum_posts', 0);
    }

    public function test_a_held_reply_file_is_not_public_and_a_held_thread_file_is_only_for_a_moderator(): void
    {
        AutomodRule::factory()->words(['cheap watches'])->create();

        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>cheap watches</p>',
            'attachments' => [$this->png('held.png')],
        ])->assertRedirect();

        $held = ForumAttachment::query()->first();
        $this->assertNotNull($held);

        auth()->logout();
        $this->get(route('forum.attachments.show', $held))->assertNotFound();

        $this->actingAs($this->author)->get(route('forum.attachments.show', $held))->assertOk();

        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $this->actingAs($this->author)->post(route('forum.threads.store', $this->board), [
            'title' => 'cheap watches for sale',
            'body' => '<p>Look</p>',
            'attachments' => [$this->png('thread.png')],
        ])->assertRedirect();

        $threadFile = ForumAttachment::query()->whereKeyNot($held->id)->first();
        $this->assertNotNull($threadFile);

        auth()->logout();
        $this->get(route('forum.attachments.show', $threadFile))->assertNotFound();
        $this->actingAs($this->author)->get(route('forum.attachments.show', $threadFile))->assertNotFound();
        $this->actingAs($moderator)->get(route('forum.attachments.show', $threadFile))->assertOk();
    }

    public function test_deleting_the_post_or_removing_the_file_or_taking_the_reply_down_drops_it(): void
    {
        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>See the picture</p>',
            'attachments' => [$this->png('photo.png')],
        ])->assertRedirect();

        $post = ForumPost::query()->first();
        $attachment = ForumAttachment::query()->first();
        $this->assertNotNull($post);
        $this->assertNotNull($attachment);
        $path = $attachment->path;

        $this->actingAs($this->author)->put(route('forum.posts.update', [$this->board, $this->thread, $post]), [
            'body' => '<p>See the picture</p>',
            'remove_attachments' => [$attachment->id],
        ])->assertRedirect();

        $this->assertDatabaseCount('forum_attachments', 0);
        Storage::disk('local')->assertMissing($path);
        $this->get(route('forum.attachments.show', $attachment->id))->assertNotFound();

        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>Another</p>',
            'attachments' => [$this->png('again.png')],
        ])->assertRedirect();

        $again = ForumPost::query()->latest('id')->first();
        $againFile = ForumAttachment::query()->first();
        $this->assertNotNull($again);
        $this->assertNotNull($againFile);

        $this->actingAs($this->author)->delete(route('forum.posts.destroy', [$this->board, $this->thread, $again]));

        $this->assertDatabaseMissing('forum_attachments', ['id' => $againFile->id]);
        Storage::disk('local')->assertMissing($againFile->path);

        $other = User::factory()->create();
        $theirThread = ForumThread::create([
            'forum_board_id' => $this->board->id,
            'user_id' => $other->id,
            'title' => 'Their thread',
            'slug' => 'their-thread',
            'excerpt' => null,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $other->id,
        ]);

        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $theirThread]), [
            'body' => '<p>In your thread</p>',
            'attachments' => [$this->png('reply.png')],
        ])->assertRedirect();

        $replyFile = ForumAttachment::query()->first();
        $this->assertNotNull($replyFile);

        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');
        app(MemberContent::class)->takeDown($this->author, $moderator, 'Spam wave');

        $this->assertDatabaseMissing('forum_attachments', ['id' => $replyFile->id]);
        Storage::disk('local')->assertMissing($replyFile->path);
    }

    public function test_an_edit_that_would_pass_five_files_does_not_publish(): void
    {
        $files = [];

        for ($i = 0; $i < 5; $i++) {
            $files[] = UploadedFile::fake()->createWithContent('note-'.$i.'.txt', 'hello');
        }

        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>Original</p>',
            'attachments' => $files,
        ])->assertRedirect();

        $post = ForumPost::query()->first();
        $this->assertNotNull($post);
        $this->assertSame(5, $post->attachments()->count());
        $body = $post->body;

        $mentioned = User::factory()->create(['nickname' => 'Heard']);
        Notification::fake();

        $this->actingAs($this->author)->put(route('forum.posts.update', [$this->board, $this->thread, $post]), [
            'body' => '<p>Updated @Heard</p>',
            'attachments' => [UploadedFile::fake()->createWithContent('extra.txt', 'more')],
        ])->assertSessionHasErrors([
            'attachments' => 'A post can have 5 files.',
        ]);

        $post->refresh();
        $this->assertSame($body, $post->body);
        $this->assertNull($post->edited_at);
        $this->assertSame(0, $post->revisions()->count());
        $this->assertSame(0, $post->mentions()->count());
        $this->assertSame(5, ForumAttachment::query()->count());
        $this->assertDatabaseMissing('forum_attachments', ['name' => 'extra.txt']);
        Notification::assertNotSentTo($mentioned, ForumPostMentioned::class);
    }

    public function test_a_failed_replacement_keeps_the_file_it_was_replacing(): void
    {
        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>See the file</p>',
            'attachments' => [UploadedFile::fake()->createWithContent('notes.txt', 'hello')],
        ])->assertRedirect();

        $post = ForumPost::query()->first();
        $attachment = ForumAttachment::query()->first();
        $this->assertNotNull($post);
        $this->assertNotNull($attachment);
        $path = $attachment->path;

        $this->useDiskThatCannotStore();

        $this->actingAs($this->author)->put(route('forum.posts.update', [$this->board, $this->thread, $post]), [
            'body' => '<p>See the file</p>',
            'attachments' => [UploadedFile::fake()->createWithContent('replacement.txt', 'next')],
            'remove_attachments' => [$attachment->id],
        ])->assertSessionHasErrors([
            'attachments' => 'The file could not be saved.',
        ]);

        $this->assertDatabaseHas('forum_attachments', ['id' => $attachment->id, 'name' => 'notes.txt']);
        Storage::disk('local')->assertExists($path);
        $this->assertSame(1, ForumAttachment::query()->count());
    }

    public function test_deleting_an_account_removes_files_on_replies_in_threads_they_started(): void
    {
        $replyAuthor = User::factory()->create();

        $this->actingAs($replyAuthor)->post(route('forum.posts.store', [$this->board, $this->thread]), [
            'body' => '<p>A reply</p>',
            'attachments' => [UploadedFile::fake()->createWithContent('reply.txt', 'hello')],
        ])->assertRedirect();

        $replyFile = ForumAttachment::query()->first();
        $this->assertNotNull($replyFile);

        $held = ForumPost::query()->create([
            'forum_thread_id' => $this->thread->id,
            'user_id' => $replyAuthor->id,
            'body' => '<p>Waiting</p>',
        ]);
        $held->forceFill(['held_at' => now()])->save();
        $heldPath = 'forum-attachments/held/note.txt';
        Storage::disk('local')->put($heldPath, 'held reply');
        $heldFile = ForumAttachment::query()->create([
            'forum_post_id' => $held->id,
            'user_id' => $replyAuthor->id,
            'disk' => 'local',
            'path' => $heldPath,
            'name' => 'note.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
        ]);

        $bystander = User::factory()->create();
        $theirThread = ForumThread::create([
            'forum_board_id' => $this->board->id,
            'user_id' => $bystander->id,
            'title' => 'Their thread',
            'slug' => 'their-thread',
            'excerpt' => null,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $bystander->id,
        ]);

        $this->actingAs($this->author)->post(route('forum.posts.store', [$this->board, $theirThread]), [
            'body' => '<p>Elsewhere</p>',
            'attachments' => [UploadedFile::fake()->createWithContent('mine.txt', 'hello')],
        ])->assertRedirect();

        $this->actingAs($bystander)->post(route('forum.posts.store', [$this->board, $theirThread]), [
            'body' => '<p>Stays</p>',
            'attachments' => [UploadedFile::fake()->createWithContent('stays.txt', 'hello')],
        ])->assertRedirect();

        $ownElsewhere = ForumAttachment::query()->where('name', 'mine.txt')->first();
        $stays = ForumAttachment::query()->where('name', 'stays.txt')->first();
        $this->assertNotNull($ownElsewhere);
        $this->assertNotNull($stays);

        $this->author->delete();

        $this->assertDatabaseMissing('forum_attachments', ['id' => $replyFile->id]);
        $this->assertDatabaseMissing('forum_attachments', ['id' => $heldFile->id]);
        $this->assertDatabaseMissing('forum_attachments', ['id' => $ownElsewhere->id]);
        Storage::disk('local')->assertMissing($replyFile->path);
        Storage::disk('local')->assertMissing($heldPath);
        Storage::disk('local')->assertMissing($ownElsewhere->path);
        $this->assertDatabaseHas('forum_attachments', ['id' => $stays->id]);
        Storage::disk('local')->assertExists($stays->path);
    }

    private function png(string $name): UploadedFile
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, imagecolorallocate($image, 20, 40, 80));
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    private function pdf(): UploadedFile
    {
        $bytes = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF";

        return UploadedFile::fake()->createWithContent('notes.pdf', $bytes);
    }

    private function useDiskThatCannotStore(): void
    {
        config([
            'filesystems.disks.full' => [
                'driver' => 'full',
                'root' => storage_path('framework/testing/disks/full'),
                'throw' => false,
            ],
            'forum.attachments.disk' => 'full',
        ]);

        Storage::extend('full', function ($app, array $config) {
            $adapter = new class($config['root']) extends LocalFilesystemAdapter
            {
                public function write(string $path, string $contents, Config $config): void
                {
                    throw UnableToWriteFile::atLocation($path, 'disk full');
                }

                public function writeStream(string $path, $contents, Config $config): void
                {
                    throw UnableToWriteFile::atLocation($path, 'disk full');
                }
            };

            return new FilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
    }
}
