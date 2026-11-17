<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\BlogRevision;
use App\Models\User;
use App\Support\Blog\BlogCovers;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The files of a cover go when the post does, and `metaforger:blog:covers:prune` clears the ones that no post names (docs/blog-covers.md).
 *
 * Deleting a post deleted its row and left every size of its cover on the public disk, and so did deleting the member who wrote it (a
 * cascade runs no model event). The files are written directly here, not drawn from an upload: what is under test is which of them go.
 */
class BlogCoverDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    /**
     * A cover at its three sizes, as BlogCovers keeps it.
     *
     * @return array{large: string, medium: string, small: string}
     */
    private function cover(string $id): array
    {
        $files = [
            'large' => "blog-covers/$id-1600x400-large.webp",
            'medium' => "blog-covers/$id-medium.webp",
            'small' => "blog-covers/$id-small.webp",
        ];

        foreach ($files as $path) {
            Storage::disk('public')->put($path, "cover $path");
        }

        return $files;
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    /**
     * @param  array<string, string>  $files
     */
    private function assertGone(array $files): void
    {
        foreach ($files as $path) {
            Storage::disk('public')->assertMissing($path);
        }
    }

    /**
     * @param  array<string, string>  $files
     */
    private function assertKept(array $files): void
    {
        foreach ($files as $path) {
            Storage::disk('public')->assertExists($path);
        }
    }

    private function snapshot(Blog $blog): void
    {
        BlogRevision::recordSnapshot($blog->fresh(['categories:id', 'tags:id']), $blog->user);
    }

    #[Test]
    public function deleting_a_post_removes_every_size_of_its_cover_and_only_its_own(): void
    {
        $mine = $this->cover('01aaaaaaaaaaaaaaaaaaaaaaaa');
        $theirs = $this->cover('01bbbbbbbbbbbbbbbbbbbbbbbb');
        $blog = Blog::factory()->create(['cover_image' => $mine['large']]);
        $other = Blog::factory()->create(['cover_image' => $theirs['large']]);
        $this->snapshot($blog);

        $this->actingAs($this->admin())->delete(route('acp.blogs.destroy', $blog))->assertRedirect();

        $this->assertNull($blog->fresh());
        $this->assertGone($mine);
        $this->assertKept($theirs);
        $this->assertNotNull($other->fresh());
    }

    #[Test]
    public function a_cover_that_is_one_file_goes_too(): void
    {
        // The first version stored `blog-covers/{random}.jpg`, and a site that never ran the upgrade command still has them.
        Storage::disk('public')->put('blog-covers/Zm9vYmFyYmF6.jpg', 'an old cover');
        $blog = Blog::factory()->create(['cover_image' => 'blog-covers/Zm9vYmFyYmF6.jpg']);

        $blog->delete();

        Storage::disk('public')->assertMissing('blog-covers/Zm9vYmFyYmF6.jpg');
    }

    #[Test]
    public function the_files_that_revisions_name_go_with_the_post(): void
    {
        // A cover that was replaced is deleted when it is replaced, but a file can outlive the row that named it (a failed delete, or a
        // restore): every cover the post's history names goes when the history does.
        $now = $this->cover('01cccccccccccccccccccccccc');
        $before = $this->cover('01dddddddddddddddddddddddd');
        $blog = Blog::factory()->create(['cover_image' => $before['large']]);
        $this->snapshot($blog);
        $blog->update(['cover_image' => $now['large']]);
        $this->snapshot($blog);

        $blog->delete();

        $this->assertSame(0, BlogRevision::query()->count());
        $this->assertGone($now);
        $this->assertGone($before);
    }

    #[Test]
    public function a_cover_that_another_post_names_stays(): void
    {
        // Nothing in the application gives two posts one file; but a row that says so is not a reason to take a file from the other.
        $shared = $this->cover('01eeeeeeeeeeeeeeeeeeeeeeee');
        $blog = Blog::factory()->create(['cover_image' => $shared['large']]);
        $another = Blog::factory()->create(['cover_image' => $shared['large']]);

        $blog->delete();
        $this->assertKept($shared);

        $another->delete();
        $this->assertGone($shared);
    }

    #[Test]
    public function a_cover_that_another_posts_revision_names_stays(): void
    {
        $shared = $this->cover('01ffffffffffffffffffffffff');
        $blog = Blog::factory()->create(['cover_image' => $shared['large']]);
        $another = Blog::factory()->create(['cover_image' => null]);
        BlogRevision::query()->create([
            'blog_id' => $another->id, 'title' => 'x', 'slug' => 'x', 'body' => '<p>x</p>', 'status' => 'draft', 'cover_image' => $shared['large'],
            'category_ids' => [], 'tag_ids' => [],
        ]);

        $blog->delete();

        $this->assertKept($shared);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pathsThatAreNotCovers(): array
    {
        return [
            'a product download' => ['products/12/game.zip'],
            'above the covers directory' => ['blog-covers/../products/12/game.zip'],
            'inside something else' => ['products/blog-covers/cover.webp'],
            'in a directory of the covers' => ['blog-covers/sub/cover.webp'],
            'a dotfile' => ['blog-covers/.htaccess'],
            'a name with a space' => ['blog-covers/my cover.webp'],
            'the directory itself' => ['blog-covers'],
        ];
    }

    #[Test]
    public function the_shape_of_a_cover_path_is_exact(): void
    {
        $covers = app(BlogCovers::class);

        foreach (['blog-covers/01hx-1600x400-large.webp', 'blog-covers/01hx-medium.webp', 'blog-covers/Zm9v_Yg-1.jpg', 'blog-covers/a'] as $path) {
            $this->assertTrue($covers->isCoverPath($path), $path);
        }

        // A new line after the name is not a name (`$` alone would let it through: the pattern ends with `D`).
        foreach ([null, '', "blog-covers/cover.webp\n", '/blog-covers/cover.webp', 'blog-covers/', 'Blog-Covers/cover.webp', 'blog-covers/-cover.webp'] as $path) {
            $this->assertFalse($covers->isCoverPath($path), (string) json_encode($path));
        }
    }

    #[Test]
    public function the_files_of_a_cover_are_all_its_sizes_and_nothing_for_a_path_that_is_not_one(): void
    {
        $covers = app(BlogCovers::class);

        $this->assertSame(
            ['blog-covers/01x-1600x400-large.webp', 'blog-covers/01x-small.webp', 'blog-covers/01x-medium.webp'],
            $covers->filesOf('blog-covers/01x-1600x400-large.webp'),
        );
        $this->assertSame(['blog-covers/old.jpg'], $covers->filesOf('blog-covers/old.jpg'));
        $this->assertSame([], $covers->filesOf('products/12/game.zip'));
    }

    #[Test]
    #[DataProvider('pathsThatAreNotCovers')]
    public function a_row_that_names_some_other_file_does_not_take_that_file(string $path): void
    {
        // A cover path is a value in a row, and nothing but its shape says it is a cover. The post may be deleted; the file it names is
        // not the post's to delete.
        Storage::disk('public')->put($path, 'not a cover');
        $blog = Blog::factory()->create(['cover_image' => $path]);

        $blog->delete();
        app(BlogCovers::class)->delete($path);

        $this->assertNull($blog->fresh());
        Storage::disk('public')->assertExists($path);

        // Nothing asks for it in the first place.
        $other = Blog::factory()->create(['cover_image' => $path]);
        $this->assertSame([], app(BlogCovers::class)->pathsOfPosts(Blog::query()->whereKey($other->id)->select('id')));
    }

    #[Test]
    public function a_disk_that_cannot_be_reached_does_not_undo_the_deletion(): void
    {
        Exceptions::fake();

        $cover = $this->cover('01gggggggggggggggggggggggg');
        $blog = Blog::factory()->create(['cover_image' => $cover['large']]);

        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('delete')->andThrow(new RuntimeException('The disk is not there.'));
        Storage::set('public', $disk);

        $blog->delete();

        $this->assertNull($blog->fresh(), 'the post is gone');
        Exceptions::assertReported(RuntimeException::class);
    }

    #[Test]
    public function a_file_the_disk_would_not_delete_is_reported_and_the_post_is_still_gone(): void
    {
        // The public disk is set not to throw: a file it cannot delete is `false`, and nothing said so.
        Exceptions::fake();

        $cover = $this->cover('01qqqqqqqqqqqqqqqqqqqqqqqq');
        $blog = Blog::factory()->create(['cover_image' => $cover['large']]);

        $this->diskThatCannotDelete($cover['medium'], throws: false);

        $blog->delete();

        $this->assertNull($blog->fresh());
        Exceptions::assertReported(fn (RuntimeException $exception) => str_contains($exception->getMessage(), $cover['large']));
    }

    /**
     * Make the public disk refuse to delete a file, the way a disk does when its directory is not writable: it says `false` (the
     * public disk is set not to throw) or, for another kind of disk, throws.
     */
    private function diskThatCannotDelete(string $stuck, bool $throws): void
    {
        $real = Storage::disk('public');

        $disk = Mockery::mock($real);
        $disk->shouldReceive('delete')->andReturnUsing(function ($paths) use ($real, $stuck, $throws) {
            if (! in_array($stuck, (array) $paths, true)) {
                return $real->delete($paths);
            }

            if ($throws) {
                throw new RuntimeException('Permission denied.');
            }

            return false;
        });

        Storage::set('public', $disk);
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function waysADiskRefuses(): array
    {
        return [
            'it says false, as the public disk does' => [false],
            'it throws, as a disk set to throw does' => [true],
        ];
    }

    #[Test]
    #[DataProvider('waysADiskRefuses')]
    public function the_prune_command_does_not_count_a_file_it_could_not_delete(bool $throws): void
    {
        $stuck = $this->cover('01rrrrrrrrrrrrrrrrrrrrrrrr');
        $fine = $this->cover('01ssssssssssssssssssssssss');
        $this->old(array_merge(array_values($stuck), array_values($fine)));

        $this->diskThatCannotDelete($stuck['medium'], $throws);

        // Six files were found and one stays: five are counted, and the command says it did not finish.
        $this->artisan('metaforger:blog:covers:prune')
            ->expectsOutputToContain($stuck['medium'].': '.($throws ? 'Permission denied.' : 'could not be deleted.'))
            ->expectsOutputToContain('5 file(s)')
            ->expectsOutputToContain('1 file(s) could not be deleted and are still there')
            ->assertFailed();

        Storage::disk('public')->assertExists($stuck['medium']);
        $this->assertGone([$stuck['large'], $stuck['small']]);
        $this->assertGone($fine);
    }

    #[Test]
    public function more_covers_than_one_batch_are_all_looked_at_for_sharing(): void
    {
        // The paths are looked up 500 at a time to see whether another post names them: one in the second batch must be spared too.
        $member = User::factory()->create();
        $other = User::factory()->create();
        $now = now();
        $rows = [];

        foreach (range(1, 501) as $n) {
            $rows[] = [
                'title' => "Post $n", 'slug' => "post-$n", 'body' => '<p>x</p>', 'user_id' => $member->id, 'status' => 'draft',
                'cover_image' => sprintf('blog-covers/%026d-large-%d.webp', $n, $n), 'preview_token' => (string) Str::uuid(),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('blogs')->insert($chunk);
        }

        Blog::factory()->create(['user_id' => $other->id, 'cover_image' => $rows[500]['cover_image']]);

        $paths = app(BlogCovers::class)->pathsOfPosts(Blog::query()->where('user_id', $member->id)->select('id'));

        $this->assertCount(500, $paths);
        $this->assertNotContains($rows[500]['cover_image'], $paths, 'named by another post, in the second batch');
        $this->assertContains($rows[0]['cover_image'], $paths);
    }

    #[Test]
    public function the_prune_command_deletes_the_files_that_no_post_names(): void
    {
        $named = $this->cover('01hhhhhhhhhhhhhhhhhhhhhhhh');
        $inHistory = $this->cover('01iiiiiiiiiiiiiiiiiiiiiiii');
        $stray = $this->cover('01jjjjjjjjjjjjjjjjjjjjjjjj');
        Storage::disk('public')->put('blog-covers/legacy-named.png', 'old cover');
        Storage::disk('public')->put('blog-covers/legacy-stray.png', 'old cover');
        Storage::disk('public')->put('blog-covers/01kkkkkkkkkkkkkkkkkkkkkkkk-small.webp', 'a size whose large file is gone');

        $blog = Blog::factory()->create(['cover_image' => $named['large']]);
        Blog::factory()->create(['cover_image' => 'blog-covers/legacy-named.png']);
        $this->snapshot($blog);
        $blog->update(['cover_image' => $named['large']]);
        BlogRevision::query()->where('blog_id', $blog->id)->update(['cover_image' => $inHistory['large']]);

        // Every file is old, so that only being named keeps one.
        $this->old(array_merge(array_values($named), array_values($inHistory), array_values($stray), ['blog-covers/legacy-named.png', 'blog-covers/legacy-stray.png', 'blog-covers/01kkkkkkkkkkkkkkkkkkkkkkkk-small.webp']));

        $this->artisan('metaforger:blog:covers:prune')->assertSuccessful();

        $this->assertKept($named);
        $this->assertKept($inHistory);
        $this->assertGone($stray);
        $this->assertGone(['blog-covers/legacy-stray.png', 'blog-covers/01kkkkkkkkkkkkkkkkkkkkkkkk-small.webp']);
        Storage::disk('public')->assertExists('blog-covers/legacy-named.png');
    }

    #[Test]
    public function the_prune_command_leaves_young_files_other_directories_and_what_is_not_a_cover(): void
    {
        $young = $this->cover('01lllllllllllllllllllllllll');
        Storage::disk('public')->put('products/12/picture.webp', 'a product picture');
        Storage::disk('public')->put('blog-covers/.gitignore', '*');
        Storage::disk('public')->put('blog-covers/my cover.webp', 'not named like a cover');
        Storage::disk('public')->put('blog-covers/sub/inside.webp', 'in another directory');

        $this->old(['products/12/picture.webp', 'blog-covers/.gitignore', 'blog-covers/my cover.webp', 'blog-covers/sub/inside.webp']);

        // A cover is written before the row that names it: a file of the last hour may be one that is being saved.
        $this->touchFiles($young, time() - 59 * 60);

        $this->artisan('metaforger:blog:covers:prune')->assertSuccessful();

        $this->assertKept($young);
        $this->assertKept(['products/12/picture.webp', 'blog-covers/.gitignore', 'blog-covers/my cover.webp', 'blog-covers/sub/inside.webp']);
    }

    #[Test]
    public function the_prune_command_takes_a_file_once_it_is_an_hour_old(): void
    {
        $edge = $this->cover('01mmmmmmmmmmmmmmmmmmmmmmmm');
        $this->touchFiles($edge, time() - 61 * 60);

        $this->artisan('metaforger:blog:covers:prune')->assertSuccessful();

        $this->assertGone($edge);
    }

    #[Test]
    public function a_dry_run_says_what_it_would_delete_and_deletes_nothing(): void
    {
        $stray = $this->cover('01nnnnnnnnnnnnnnnnnnnnnnnn');
        $this->old($stray);

        $this->artisan('metaforger:blog:covers:prune', ['--dry-run' => true])
            ->expectsOutputToContain('would delete '.$stray['large'])
            ->expectsOutputToContain('3 file(s)')
            ->assertSuccessful();

        $this->assertKept($stray);

        $this->artisan('metaforger:blog:covers:prune')->expectsOutputToContain('3 file(s)')->assertSuccessful();
        $this->artisan('metaforger:blog:covers:prune')->expectsOutputToContain('0 file(s)')->assertSuccessful();
    }

    /**
     * Make files look as if they were written two hours ago.
     *
     * @param  array<int|string, string>  $files
     */
    private function old(array $files): void
    {
        $this->touchFiles($files, time() - 7200);
    }

    /**
     * @param  array<int|string, string>  $files
     */
    private function touchFiles(array $files, int $time): void
    {
        foreach ($files as $path) {
            touch(Storage::disk('public')->path($path), $time);
        }
    }
}
