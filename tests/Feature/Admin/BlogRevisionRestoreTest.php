<?php

namespace Tests\Feature\Admin;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogRevision;
use App\Models\BlogTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogRevisionRestoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'editor']);
        Role::firstOrCreate(['name' => 'moderator']);
    }

    public function test_editor_can_restore_revision_and_updates_blog(): void
    {
        $editor = User::factory()->create();
        $editor->assignRole('editor');
        $this->actingAs($editor);

        $blog = Blog::factory()->create([
            'title' => 'Current Title',
            'slug' => 'current-title',
            'excerpt' => 'Current excerpt',
            'body' => 'Current body',
            'status' => 'draft',
            'cover_image' => 'covers/current.jpg',
        ]);

        $currentCategory = BlogCategory::factory()->create();
        $currentTag = BlogTag::factory()->create();
        $blog->categories()->sync([$currentCategory->id]);
        $blog->tags()->sync([$currentTag->id]);

        $targetCategory = BlogCategory::factory()->create();
        $targetTag = BlogTag::factory()->create();

        $publishedAt = Carbon::now()->subDay();

        $revision = BlogRevision::factory()
            ->for($blog)
            ->for($editor, 'editor')
            ->create([
                'title' => 'Archived Title',
                'excerpt' => 'Archived excerpt',
                'body' => 'Archived body',
                'metadata' => [
                    'slug' => 'archived-title',
                    'status' => 'published',
                    'cover_image' => 'covers/example.jpg',
                    'published_at' => $publishedAt->toIso8601String(),
                    'scheduled_for' => null,
                    'category_ids' => [$targetCategory->id],
                    'tag_ids' => [$targetTag->id],
                ],
            ]);

        $response = $this->post(route('acp.blogs.revisions.restore', [
            'blog' => $blog->id,
            'revision' => $revision->id,
        ]));

        $response->assertRedirect(route('acp.blogs.edit', ['blog' => $blog->id]));

        $blog->refresh();

        $this->assertSame('Archived Title', $blog->title);
        $this->assertSame('Archived excerpt', $blog->excerpt);
        $this->assertSame('Archived body', $blog->body);
        $this->assertSame('archived-title', $blog->slug);
        $this->assertSame('published', $blog->status);
        $this->assertSame('covers/example.jpg', $blog->cover_image);
        $this->assertTrue($blog->published_at?->equalTo($publishedAt));
        $this->assertNull($blog->scheduled_for);

        $this->assertEqualsCanonicalizing(
            [$targetCategory->id],
            $blog->categories()->pluck('blog_categories.id')->all()
        );
        $this->assertEqualsCanonicalizing(
            [$targetTag->id],
            $blog->tags()->pluck('blog_tags.id')->all()
        );

        $latestRevision = $blog->revisions()->latest()->first();
        $this->assertNotNull($latestRevision);
        $this->assertNotSame($revision->id, $latestRevision->id);
        $this->assertSame($blog->title, $latestRevision->title);
        $this->assertSame('published', $latestRevision->metadata['status'] ?? null);

        $this->assertSame(2, $blog->revisions()->count());
    }

    public function test_moderator_cannot_restore_revision(): void
    {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');
        $this->actingAs($moderator);

        $blog = Blog::factory()->create([
            'title' => 'Initial Title',
            'excerpt' => 'Initial excerpt',
            'body' => 'Initial body',
        ]);

        $revision = BlogRevision::factory()->for($blog)->create([
            'title' => 'Older Title',
            'metadata' => [
                'slug' => 'older-title',
                'status' => 'draft',
                'category_ids' => [],
                'tag_ids' => [],
            ],
        ]);

        $response = $this->post(route('acp.blogs.revisions.restore', [
            'blog' => $blog->id,
            'revision' => $revision->id,
        ]));

        $response->assertForbidden();

        $blog->refresh();
        $this->assertSame('Initial Title', $blog->title);
    }
}
