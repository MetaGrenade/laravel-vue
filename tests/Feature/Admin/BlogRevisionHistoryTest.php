<?php

namespace Tests\Feature\Admin;

use App\Models\Blog;
use App\Models\BlogCategory;
use App\Models\BlogRevision;
use App\Models\BlogTag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogRevisionHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'admin']);
        Role::firstOrCreate(['name' => 'moderator']);
        Role::firstOrCreate(['name' => 'editor']);
    }

    private function actingAsAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin);

        return $admin;
    }

    private function actingAsModerator(): User
    {
        $moderator = User::factory()->create();
        $moderator->assignRole('moderator');

        $this->actingAs($moderator);

        return $moderator;
    }

    private function actingAsEditor(): User
    {
        $editor = User::factory()->create();
        $editor->assignRole('editor');

        $this->actingAs($editor);

        return $editor;
    }

    public function test_revision_snapshot_recorded_on_update(): void
    {
        $admin = $this->actingAsAdmin();

        $blog = Blog::factory()->create([
            'title' => 'Original Title',
            'slug' => 'original-title',
            'body' => '<p>Original body</p>',
            'status' => 'draft',
        ]);

        $initialCategory = BlogCategory::factory()->create();
        $initialTag = BlogTag::factory()->create();
        $blog->categories()->sync([$initialCategory->id]);
        $blog->tags()->sync([$initialTag->id]);

        $newCategory = BlogCategory::factory()->create();
        $newTag = BlogTag::factory()->create();

        $response = $this->put(route('acp.blogs.update', $blog), [
            'title' => 'Updated Title',
            'excerpt' => 'Updated excerpt',
            'body' => '<p>Updated body</p>',
            'status' => 'draft',
            'category_ids' => [$newCategory->id],
            'tag_ids' => [$newTag->id],
            'scheduled_for' => '',
        ]);

        $response->assertRedirect(route('acp.blogs.index'));

        $revision = BlogRevision::query()->latest()->first();

        $this->assertNotNull($revision, 'Expected a revision to be recorded after update.');
        $this->assertSame($blog->id, $revision->blog_id);
        $this->assertSame('Updated Title', $revision->title);
        $this->assertSame(Str::slug('Updated Title'), $revision->slug);
        $this->assertSame('<p>Updated body</p>', $revision->body);
        $this->assertSame('Updated excerpt', $revision->excerpt);
        $this->assertSame([$newCategory->id], $revision->category_ids);
        $this->assertSame([$newTag->id], $revision->tag_ids);
        $this->assertSame($admin->id, $revision->edited_by_id);
    }

    public function test_moderator_can_view_revision_history(): void
    {
        $moderator = $this->actingAsModerator();

        $blog = Blog::factory()->create();
        $blog->categories()->sync(BlogCategory::factory()->count(2)->create()->pluck('id'));
        $blog->tags()->sync(BlogTag::factory()->count(2)->create()->pluck('id'));

        BlogRevision::recordSnapshot($blog->fresh(['categories:id', 'tags:id']), $moderator);

        $response = $this->get(route('acp.blogs.revisions.index', $blog));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('acp/BlogRevisionHistory')
            ->where('blog.id', $blog->id)
            ->where('permissions.canRestore', true)
        );
    }

    public function test_author_can_view_revision_history(): void
    {
        $author = User::factory()->create();
        $blog = Blog::factory()->for($author)->create();

        $this->actingAs($author);

        BlogRevision::recordSnapshot($blog->fresh(['categories:id', 'tags:id']), $author);

        $response = $this->get(route('acp.blogs.revisions.index', $blog));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('acp/BlogRevisionHistory')
            ->where('blog.id', $blog->id)
            ->where('permissions.canRestore', true)
        );
    }

    public function test_editor_cannot_view_revision_history_for_others(): void
    {
        $editor = $this->actingAsEditor();

        $blog = Blog::factory()->create();

        BlogRevision::recordSnapshot($blog->fresh(['categories:id', 'tags:id']), $editor);

        $this->get(route('acp.blogs.revisions.index', $blog))->assertForbidden();
    }

    public function test_author_can_restore_revision(): void
    {
        $author = User::factory()->create();
        $blog = Blog::factory()->for($author)->create([
            'title' => 'Original Title',
            'slug' => 'original-title',
            'body' => '<p>Original body</p>',
            'excerpt' => 'Original excerpt',
            'status' => 'draft',
        ]);

        $originalCategory = BlogCategory::factory()->create();
        $originalTag = BlogTag::factory()->create();
        $blog->categories()->sync([$originalCategory->id]);
        $blog->tags()->sync([$originalTag->id]);

        $this->actingAs($author);

        $originalSnapshot = BlogRevision::recordSnapshot($blog->fresh(['categories:id', 'tags:id']), $author);

        $newCategory = BlogCategory::factory()->create();
        $newTag = BlogTag::factory()->create();

        $blog->forceFill([
            'title' => 'New Title',
            'slug' => 'new-title',
            'body' => '<p>New body</p>',
            'excerpt' => 'New excerpt',
            'status' => 'published',
        ])->save();
        $blog->categories()->sync([$newCategory->id]);
        $blog->tags()->sync([$newTag->id]);

        $response = $this->put(route('acp.blogs.revisions.restore', [$blog, $originalSnapshot]));

        $response->assertRedirect(route('acp.blogs.revisions.index', $blog));

        $blog->refresh();

        $this->assertSame('Original Title', $blog->title);
        $this->assertSame('original-title', $blog->slug);
        $this->assertSame('<p>Original body</p>', $blog->body);
        $this->assertSame('Original excerpt', $blog->excerpt);
        $this->assertSame('draft', $blog->status);
        $this->assertEqualsCanonicalizing([$originalCategory->id], $blog->categories()->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$originalTag->id], $blog->tags()->pluck('id')->all());

        $this->assertSame(3, BlogRevision::query()->count());
        $this->assertSame('Original Title', BlogRevision::query()->latest()->first()->title);
    }
}
