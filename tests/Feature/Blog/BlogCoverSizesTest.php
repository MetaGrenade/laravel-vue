<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\BlogRevision;
use App\Models\User;
use App\Support\Blog\BlogCovers;
use App\Support\Commerce\Catalogue\ImageProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A cover is kept at three widths and each page asks for the one it needs (docs/images.md).
 */
class BlogCoverSizesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            $this->markTestSkipped('GD with WebP support is required.');
        }

        Storage::fake('public');
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    }

    #[Test]
    public function a_cover_is_kept_at_three_widths(): void
    {
        $blog = $this->postWithCover(1200, 600);

        // The largest file's name says how big it is; the others are named after the same id.
        $this->assertMatchesRegularExpression('#^blog-covers/[0-9a-z]{26}-1200x600-large\.webp$#', $blog->cover_image);
        $this->assertSame([1200, 600], $this->sizeOf($blog->cover_image));
        $this->assertSame([960, 480], $this->sizeOf($this->sibling($blog->cover_image, 'medium')));
        $this->assertSame([480, 240], $this->sizeOf($this->sibling($blog->cover_image, 'small')));
        $this->assertMatchesRegularExpression('#^blog-covers/[0-9a-z]{26}-medium\.webp$#', $this->sibling($blog->cover_image, 'medium'));
        $this->assertCount(3, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function the_smaller_widths_are_measured_on_the_width_so_a_tall_cover_stays_sharp_in_a_wide_card(): void
    {
        $blog = $this->postWithCover(600, 1200);

        // Cut to a longest side of 1600 first (not needed here), then scaled by width: 600 is narrower than 960.
        $this->assertStringEndsWith('-600x1200-large.webp', $blog->cover_image);
        $this->assertSame([600, 1200], $this->sizeOf($blog->cover_image));
        $this->assertSame([600, 1200], $this->sizeOf($this->sibling($blog->cover_image, 'medium')));
        $this->assertSame([480, 960], $this->sizeOf($this->sibling($blog->cover_image, 'small')));
    }

    #[Test]
    public function no_size_is_larger_than_the_picture_that_was_uploaded(): void
    {
        $blog = $this->postWithCover(300, 150);

        foreach ([$blog->cover_image, $this->sibling($blog->cover_image, 'medium'), $this->sibling($blog->cover_image, 'small')] as $path) {
            $this->assertSame([300, 150], $this->sizeOf($path));
        }
    }

    #[Test]
    public function the_pages_offer_every_size_of_a_cover_and_the_post_still_names_the_largest_for_sharing(): void
    {
        $blog = $this->postWithCover(1200, 600);
        $disk = Storage::disk('public');

        $srcset = implode(', ', [
            $disk->url($this->sibling($blog->cover_image, 'small')).' 480w',
            $disk->url($this->sibling($blog->cover_image, 'medium')).' 960w',
            $disk->url($blog->cover_image).' 1200w',
        ]);

        $this->get(route('blogs.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('blogs.data.0.cover_image', $disk->url($blog->cover_image))
            ->where('blogs.data.0.cover_srcset', $srcset)
            ->where('blogs.data.0.cover_aspect', 2));

        $this->get(route('blogs.view', ['slug' => $blog->slug]))->assertOk()->assertInertia(fn ($page) => $page
            ->where('blog.cover_image', $disk->url($blog->cover_image))
            ->where('blog.cover_srcset', $srcset)
            ->where('blog.cover_aspect', 2));

        $other = Blog::factory()->published()->create();

        $this->get(route('blogs.view', ['slug' => $other->slug]))->assertOk()->assertInertia(fn ($page) => $page
            ->where('blog.cover_srcset', null)
            ->where('blog.cover_aspect', null)
            ->where('blog.recommendations.0.cover_image', $disk->url($blog->cover_image))
            ->where('blog.recommendations.0.cover_srcset', $srcset)
            ->where('blog.recommendations.0.cover_aspect', 2));
    }

    #[Test]
    public function the_page_is_told_how_wide_the_cover_is_so_it_can_allow_for_a_wide_one_in_a_short_box(): void
    {
        // A wide cover in a box of fixed height is scaled to the height and cut at the sides: it needs more pixels
        // across than the box is wide, which only the page can work out, from these proportions.
        $blog = $this->postWithCover(1600, 400);

        $this->assertStringEndsWith('-1600x400-large.webp', $blog->cover_image);

        $this->get(route('blogs.index'))->assertOk()->assertInertia(fn ($page) => $page->where('blogs.data.0.cover_aspect', 4));

        $tall = $this->postWithCover(600, 1200, 'A tall story');

        $this->get(route('blogs.view', ['slug' => $tall->slug]))->assertOk()->assertInertia(fn ($page) => $page->where('blog.cover_aspect', 0.5));
    }

    #[Test]
    public function the_widths_in_the_srcset_are_the_widths_of_the_files(): void
    {
        $disk = Storage::disk('public');

        // 600 pixels wide: the 960 size is the same picture as the largest, so it is offered once, at 600.
        $tall = $this->postWithCover(600, 1200);

        $this->assertSame(
            $disk->url($this->sibling($tall->cover_image, 'small')).' 480w, '.$disk->url($this->sibling($tall->cover_image, 'medium')).' 600w',
            app(BlogCovers::class)->srcset($tall->cover_image),
        );

        // 300 pixels wide: all three are the same picture.
        $narrow = $this->postWithCover(300, 150, 'A narrow story');

        $this->assertSame(
            $disk->url($this->sibling($narrow->cover_image, 'small')).' 300w',
            app(BlogCovers::class)->srcset($narrow->cover_image),
        );
    }

    #[Test]
    public function a_cover_that_has_one_file_is_shown_as_that_file(): void
    {
        Storage::disk('public')->put('blog-covers/older.webp', 'one-file');

        $blog = Blog::factory()->published()->create(['cover_image' => 'blog-covers/older.webp']);

        $this->get(route('blogs.view', ['slug' => $blog->slug]))->assertOk()->assertInertia(fn ($page) => $page
            ->where('blog.cover_image', Storage::disk('public')->url('blog-covers/older.webp'))
            ->where('blog.cover_srcset', null)
            ->where('blog.cover_aspect', null));
    }

    #[Test]
    public function a_post_without_a_cover_has_neither(): void
    {
        $blog = Blog::factory()->published()->create(['cover_image' => null]);

        $this->get(route('blogs.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('blogs.data.0.cover_image', null)
            ->where('blogs.data.0.cover_srcset', null)
            ->where('blogs.data.0.cover_aspect', null));

        $this->get(route('blogs.view', ['slug' => $blog->slug]))->assertOk()->assertInertia(fn ($page) => $page
            ->where('blog.cover_image', null)
            ->where('blog.cover_srcset', null)
            ->where('blog.cover_aspect', null));
    }

    #[Test]
    public function every_size_comes_from_wherever_the_public_disk_is_served_from(): void
    {
        // A CDN in front of the files is one setting of the disk, not something the pages know about.
        Storage::fake('public', ['url' => 'https://cdn.example.test/media']);

        $blog = $this->postWithCover(1200, 600);

        $cdn = 'https://cdn.example.test/media/';

        $this->get(route('blogs.index'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('blogs.data.0.cover_image', $cdn.$blog->cover_image)
            ->where('blogs.data.0.cover_srcset', implode(', ', [
                $cdn.$this->sibling($blog->cover_image, 'small').' 480w',
                $cdn.$this->sibling($blog->cover_image, 'medium').' 960w',
                $cdn.$blog->cover_image.' 1200w',
            ])));
    }

    #[Test]
    public function replacing_a_cover_removes_every_size_of_the_old_one(): void
    {
        $blog = $this->postWithCover(1200, 600);
        $old = Storage::disk('public')->allFiles();

        $this->actingAs($this->admin())->put(route('acp.blogs.update', $blog), [
            'title' => $blog->title,
            'body' => $blog->body,
            'status' => 'published',
            'cover_image' => UploadedFile::fake()->image('next.png', 1000, 500),
        ])->assertSessionHasNoErrors();

        $blog->refresh();
        $now = Storage::disk('public')->allFiles();

        $this->assertCount(3, $now);
        $this->assertSame([], array_intersect($old, $now));
        $this->assertContains($blog->cover_image, $now);
    }

    #[Test]
    public function a_refused_replacement_keeps_every_size_of_the_old_cover(): void
    {
        $blog = $this->postWithCover(1200, 600);
        $before = Storage::disk('public')->allFiles();

        config(['commerce.images.max_pixels' => 10]);

        $this->actingAs($this->admin())->put(route('acp.blogs.update', $blog), [
            'title' => $blog->title,
            'body' => $blog->body,
            'status' => 'published',
            'cover_image' => UploadedFile::fake()->image('big.png', 8, 8),
        ])->assertSessionHasErrors('cover_image');

        $this->assertSame($before, Storage::disk('public')->allFiles());
        $this->assertSame($blog->cover_image, $blog->fresh()->cover_image);
    }

    #[Test]
    public function the_upgrade_command_draws_a_cover_that_is_one_file_at_every_size(): void
    {
        Storage::disk('public')->put('blog-covers/old.png', UploadedFile::fake()->image('old.png', 1200, 600)->getContent());

        $blog = Blog::factory()->create(['cover_image' => 'blog-covers/old.png']);
        $revision = $this->revisionOf($blog, 'blog-covers/old.png');
        $untouched = Blog::factory()->create(['cover_image' => null]);
        $stamp = $blog->updated_at->toIso8601String();

        $this->travel(1)->hours();

        $this->artisan('metaforge:blog:covers:upgrade')->assertSuccessful();

        $path = $blog->fresh()->cover_image;

        $this->assertStringEndsWith('-large.webp', $path);
        $this->assertSame($path, $revision->fresh()->cover_image, 'a revision that restores this cover finds it');
        $this->assertNull($untouched->fresh()->cover_image);
        $this->assertSame($stamp, $blog->fresh()->updated_at->toIso8601String(), 'nobody edited the post');
        $this->assertSame([1200, 600], $this->sizeOf($path));
        $this->assertSame([960, 480], $this->sizeOf($this->sibling($path, 'medium')));
        $this->assertSame([480, 240], $this->sizeOf($this->sibling($path, 'small')));
        Storage::disk('public')->assertMissing('blog-covers/old.png');
        $this->assertCount(3, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function the_upgrade_command_changes_nothing_the_second_time(): void
    {
        Storage::disk('public')->put('blog-covers/old.png', UploadedFile::fake()->image('old.png', 800, 400)->getContent());
        $blog = Blog::factory()->create(['cover_image' => 'blog-covers/old.png']);

        $this->artisan('metaforge:blog:covers:upgrade')->assertSuccessful();

        $path = $blog->fresh()->cover_image;
        $files = Storage::disk('public')->allFiles();

        $this->artisan('metaforge:blog:covers:upgrade')->expectsOutputToContain('0 cover(s) are stored as one file.')->assertSuccessful();

        $this->assertSame($path, $blog->fresh()->cover_image);
        $this->assertSame($files, Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_dry_run_says_what_it_would_do_and_does_nothing(): void
    {
        Storage::disk('public')->put('blog-covers/old.png', UploadedFile::fake()->image('old.png', 800, 400)->getContent());
        $blog = Blog::factory()->create(['cover_image' => 'blog-covers/old.png']);

        $this->artisan('metaforge:blog:covers:upgrade', ['--dry-run' => true])
            ->expectsOutputToContain('would redraw blog-covers/old.png')
            ->assertSuccessful();

        $this->assertSame('blog-covers/old.png', $blog->fresh()->cover_image);
        $this->assertSame(['blog-covers/old.png'], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_cover_with_no_file_and_a_file_that_is_not_a_picture_are_left_as_they_are(): void
    {
        Storage::disk('public')->put('blog-covers/notes.png', 'this is not a picture');

        $gone = Blog::factory()->create(['cover_image' => 'blog-covers/gone.png']);
        $notes = Blog::factory()->create(['cover_image' => 'blog-covers/notes.png']);

        $this->artisan('metaforge:blog:covers:upgrade')->assertSuccessful();

        $this->assertSame('blog-covers/gone.png', $gone->fresh()->cover_image);
        $this->assertSame('blog-covers/notes.png', $notes->fresh()->cover_image);
        $this->assertSame(['blog-covers/notes.png'], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_cover_replaced_while_the_command_runs_is_not_taken_over_and_what_was_drawn_for_it_is_removed(): void
    {
        Storage::disk('public')->put('blog-covers/old.png', UploadedFile::fake()->image('old.png', 800, 400)->getContent());
        $blog = Blog::factory()->create(['cover_image' => 'blog-covers/old.png']);

        // Somebody replaces the post's cover after the command has drawn the old one again, before it saves the new name.
        $this->app->bind(BlogCovers::class, fn ($app) => new class($app->make(ImageProcessor::class)) extends BlogCovers
        {
            public function upgrade(string $path): ?string
            {
                $drawn = parent::upgrade($path);

                Blog::query()->toBase()->where('cover_image', $path)->update(['cover_image' => 'blog-covers/replacement-large.webp']);

                return $drawn;
            }
        });

        $this->artisan('metaforge:blog:covers:upgrade')->assertSuccessful();

        $this->assertSame('blog-covers/replacement-large.webp', $blog->fresh()->cover_image);
        $this->assertSame(['blog-covers/old.png'], Storage::disk('public')->allFiles(), 'what was drawn for it is gone, and the old file is not the command to remove');
    }

    private function postWithCover(int $width, int $height, string $title = 'A story with a cover'): Blog
    {
        $this->actingAs($this->admin())->post(route('acp.blogs.store'), [
            'title' => $title,
            'body' => '<p>Hello</p>',
            'status' => 'published',
            'cover_image' => UploadedFile::fake()->image('cover.png', $width, $height),
        ])->assertSessionHasNoErrors();

        $blog = Blog::query()->where('slug', Str::slug($title))->firstOrFail();

        $this->assertStringEndsWith("-{$width}x{$height}-large.webp", $blog->cover_image);

        // What follows is seen by a visitor who is not signed in.
        $this->app['auth']->forgetGuards();

        return $blog;
    }

    private function revisionOf(Blog $blog, string $cover): BlogRevision
    {
        BlogRevision::recordSnapshot($blog->fresh(['categories:id', 'tags:id']), $blog->user);

        return tap(BlogRevision::query()->where('blog_id', $blog->id)->latest('id')->firstOrFail(), function (BlogRevision $revision) use ($cover) {
            $this->assertSame($cover, $revision->cover_image);
        });
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function sizeOf(string $path): array
    {
        $info = getimagesizefromstring(Storage::disk('public')->get($path));

        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);

        return [$info[0], $info[1]];
    }

    private function sibling(string $path, string $size): string
    {
        return preg_replace('/-\d+x\d+-large\.webp$/', "-$size.webp", $path);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }
}
