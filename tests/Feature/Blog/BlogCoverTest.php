<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogCoverTest extends TestCase
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
    public function a_cover_is_stored_as_webp_and_shown_on_the_post(): void
    {
        config(['commerce.images.sizes.large' => 100]);

        $this->actingAs($this->admin())->post(route('acp.blogs.store'), [
            'title' => 'A cover story',
            'body' => '<p>Hello</p>',
            'status' => 'published',
            'cover_image' => $this->pngWithNote('cover.png', 'GPS-LOCATION-51.5', 400, 200),
        ])->assertRedirect(route('acp.blogs.index'))->assertSessionHasNoErrors();

        $blog = Blog::query()->where('slug', 'a-cover-story')->firstOrFail();

        $this->assertNotNull($blog->cover_image);
        $this->assertStringEndsWith('.webp', $blog->cover_image);
        Storage::disk('public')->assertExists($blog->cover_image);

        $stored = Storage::disk('public')->get($blog->cover_image);
        $info = getimagesizefromstring($stored);

        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
        $this->assertSame([100, 50], [$info[0], $info[1]]);
        $this->assertStringNotContainsString('GPS-LOCATION', $stored);

        $this->get(route('blogs.view', ['slug' => $blog->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where(
                'blog.cover_image',
                Storage::disk('public')->url($blog->cover_image),
            ));
    }

    #[Test]
    public function a_file_that_is_not_a_picture_is_refused_and_nothing_is_stored(): void
    {
        $this->actingAs($this->admin())->post(route('acp.blogs.store'), [
            'title' => 'Not a picture',
            'body' => '<p>Hello</p>',
            'status' => 'draft',
            'cover_image' => UploadedFile::fake()->create('notes.png', 1, 'text/plain'),
        ])->assertSessionHasErrors('cover_image');

        $this->assertSame(0, Blog::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function a_picture_with_too_many_pixels_is_refused_and_nothing_is_stored(): void
    {
        config(['commerce.images.max_pixels' => 10]);

        $this->actingAs($this->admin())->post(route('acp.blogs.store'), [
            'title' => 'Too many pixels',
            'body' => '<p>Hello</p>',
            'status' => 'draft',
            'cover_image' => UploadedFile::fake()->image('big.png', 8, 8),
        ])->assertSessionHasErrors('cover_image');

        $this->assertSame(0, Blog::query()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function replacing_a_cover_keeps_the_old_file_when_the_new_one_is_refused(): void
    {
        config(['commerce.images.max_pixels' => 10]);

        Storage::disk('public')->put('blog-covers/old.png', 'original-bytes');

        $blog = Blog::factory()->create([
            'title' => 'Already covered',
            'slug' => 'already-covered',
            'cover_image' => 'blog-covers/old.png',
            'status' => 'draft',
        ]);

        $this->actingAs($this->admin())->put(route('acp.blogs.update', $blog), [
            'title' => 'Already covered',
            'body' => $blog->body,
            'status' => 'draft',
            'cover_image' => UploadedFile::fake()->image('big.png', 8, 8),
        ])->assertSessionHasErrors('cover_image');

        $blog->refresh();

        $this->assertSame('blog-covers/old.png', $blog->cover_image);
        Storage::disk('public')->assertExists('blog-covers/old.png');
        $this->assertSame('original-bytes', Storage::disk('public')->get('blog-covers/old.png'));
        $this->assertSame(['blog-covers/old.png'], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function replacing_a_cover_removes_the_previous_file(): void
    {
        Storage::disk('public')->put('blog-covers/old.png', 'original-bytes');

        $blog = Blog::factory()->create([
            'title' => 'Already covered',
            'slug' => 'already-covered',
            'cover_image' => 'blog-covers/old.png',
            'status' => 'draft',
        ]);

        $this->actingAs($this->admin())->put(route('acp.blogs.update', $blog), [
            'title' => 'Already covered',
            'body' => $blog->body,
            'status' => 'draft',
            'cover_image' => UploadedFile::fake()->image('next.png', 80, 40),
        ])->assertRedirect(route('acp.blogs.index'))->assertSessionHasNoErrors();

        $blog->refresh();

        $this->assertNotSame('blog-covers/old.png', $blog->cover_image);
        $this->assertStringEndsWith('.webp', (string) $blog->cover_image);
        Storage::disk('public')->assertMissing('blog-covers/old.png');
        Storage::disk('public')->assertExists((string) $blog->cover_image);

        $info = getimagesizefromstring(Storage::disk('public')->get((string) $blog->cover_image));

        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_WEBP, $info[2]);
    }

    private function admin(): User
    {
        return User::factory()->create()->assignRole('admin');
    }

    private function pngWithNote(string $name, string $note, int $width, int $height): UploadedFile
    {
        $png = UploadedFile::fake()->image('plain.png', $width, $height)->getContent();
        $chunk = 'tEXt'.'Comment'."\0".$note;
        $text = pack('N', strlen($chunk) - 4).$chunk.pack('N', crc32($chunk));
        $withNote = substr($png, 0, -12).$text.substr($png, -12);

        $this->assertStringContainsString($note, $withNote);

        return UploadedFile::fake()->createWithContent($name, $withNote);
    }
}
