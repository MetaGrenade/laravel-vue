<?php

namespace Tests\Feature\Admin;

use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BlogAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('editor');

        foreach (['view', 'create', 'edit', 'delete', 'publish'] as $ability) {
            Permission::findOrCreate('blogs.acp.'.$ability);
        }
    }

    #[Test]
    public function an_editor_without_a_blog_permission_cannot_open_or_change_a_post(): void
    {
        $editor = $this->editor();
        $blog = Blog::factory()->create();

        $this->actingAs($editor)->get(route('acp.blogs.index'))->assertForbidden();
        $this->actingAs($editor)->post(route('acp.blogs.store'), $this->postPayload())->assertForbidden();
        $this->actingAs($editor)->put(route('acp.blogs.publish', $blog))->assertForbidden();
        $this->actingAs($editor)->delete(route('acp.blogs.destroy', $blog))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.blog-comments.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.blog-tags.index'))->assertForbidden();

        $this->assertNotNull($blog->fresh());
    }

    #[Test]
    public function each_blog_permission_allows_only_its_own_action(): void
    {
        $blog = Blog::factory()->create(['status' => 'draft']);

        $viewer = $this->editor('blogs.acp.view');
        $this->actingAs($viewer)->get(route('acp.blogs.index'))->assertOk();
        $this->actingAs($viewer)->get(route('acp.blog-comments.index'))->assertOk();
        $this->actingAs($viewer)->post(route('acp.blogs.store'), $this->postPayload())->assertForbidden();
        $this->actingAs($viewer)->put(route('acp.blogs.publish', $blog))->assertForbidden();
        $this->actingAs($viewer)->delete(route('acp.blogs.destroy', $blog))->assertForbidden();

        $author = $this->editor('blogs.acp.create');
        $this->actingAs($author)->post(route('acp.blogs.store'), $this->postPayload())
            ->assertRedirect();
        $this->assertDatabaseHas('blogs', ['title' => 'A permitted post']);
        $this->actingAs($author)->get(route('acp.blogs.index'))->assertForbidden();
        $this->actingAs($author)->getJson(route('acp.blog-tags.index'))->assertOk();

        $publisher = $this->editor('blogs.acp.publish');
        $this->actingAs($publisher)->put(route('acp.blogs.publish', $blog))->assertRedirect();
        $this->assertSame('published', $blog->fresh()->status);
        $this->actingAs($publisher)->delete(route('acp.blogs.destroy', $blog))->assertForbidden();

        $remover = $this->editor('blogs.acp.delete');
        $this->actingAs($remover)->delete(route('acp.blogs.destroy', $blog))->assertRedirect();
        $this->assertNull($blog->fresh());
    }

    #[Test]
    public function an_editor_cannot_change_someone_elses_comment_and_the_author_still_can(): void
    {
        $author = User::factory()->create();
        $comment = BlogComment::factory()->for($author)->create();
        $editor = $this->editor();

        $this->assertTrue(Gate::forUser($author)->allows('update', $comment));
        $this->assertTrue(Gate::forUser($author)->allows('delete', $comment));
        $this->assertFalse(Gate::forUser($editor)->allows('update', $comment));
        $this->assertFalse(Gate::forUser($editor)->allows('delete', $comment));

        $editor->givePermissionTo('blogs.acp.edit');

        $this->assertTrue(Gate::forUser($editor)->allows('update', $comment));
        $this->assertFalse(Gate::forUser($editor)->allows('delete', $comment));
    }

    #[Test]
    public function saving_a_post_cannot_change_its_publication_without_the_publish_permission(): void
    {
        $author = $this->editor('blogs.acp.create');

        $this->actingAs($author)->get(route('acp.blogs.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('canPublish', false));

        foreach (['published', 'scheduled', 'archived'] as $status) {
            $payload = [
                'title' => 'Not '.$status,
                'body' => '<p>Hello</p>',
                'status' => $status,
            ];

            if ($status === 'scheduled') {
                $payload['scheduled_for'] = now()->addDay()->toDateTimeString();
            }

            $this->actingAs($author)->post(route('acp.blogs.store'), $payload)->assertForbidden();
            $this->assertDatabaseMissing('blogs', ['title' => 'Not '.$status]);
        }

        $publisher = $this->editor(['blogs.acp.create', 'blogs.acp.publish']);

        $this->actingAs($publisher)->post(route('acp.blogs.store'), [
            'title' => 'A published post',
            'body' => '<p>Hello</p>',
            'status' => 'published',
        ])->assertRedirect();

        $this->assertDatabaseHas('blogs', [
            'title' => 'A published post',
            'status' => 'published',
        ]);

        $editor = $this->editor('blogs.acp.edit');
        $draft = Blog::factory()->create([
            'status' => 'draft',
            'title' => 'Draft title',
            'body' => '<p>Old</p>',
        ]);
        $live = Blog::factory()->published()->create([
            'title' => 'Live title',
            'body' => '<p>Old</p>',
        ]);

        $this->actingAs($editor)->get(route('acp.blogs.edit', $draft))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('permissions.canPublish', false));

        $this->actingAs($editor)->put(route('acp.blogs.update', $draft), [
            'title' => 'Draft title revised',
            'body' => '<p>New</p>',
            'status' => 'draft',
        ])->assertRedirect();

        $draft->refresh();
        $this->assertSame('draft', $draft->status);
        $this->assertSame('Draft title revised', $draft->title);

        $this->actingAs($editor)->put(route('acp.blogs.update', $draft), [
            'title' => 'Should stay a draft',
            'body' => '<p>New</p>',
            'status' => 'published',
        ])->assertForbidden();

        $draft->refresh();
        $this->assertSame('draft', $draft->status);
        $this->assertSame('Draft title revised', $draft->title);

        $this->actingAs($editor)->put(route('acp.blogs.update', $live), [
            'title' => 'Live title revised',
            'body' => '<p>New</p>',
            'status' => 'published',
        ])->assertRedirect();

        $live->refresh();
        $this->assertSame('published', $live->status);
        $this->assertSame('Live title revised', $live->title);

        foreach (['draft', 'archived', 'scheduled'] as $status) {
            $payload = [
                'title' => 'Should stay published',
                'body' => '<p>New</p>',
                'status' => $status,
            ];

            if ($status === 'scheduled') {
                $payload['scheduled_for'] = now()->addDay()->toDateTimeString();
            }

            $this->actingAs($editor)->put(route('acp.blogs.update', $live), $payload)->assertForbidden();

            $live->refresh();
            $this->assertSame('published', $live->status);
            $this->assertSame('Live title revised', $live->title);
        }
    }

    /**
     * @param  string|list<string>|null  $permission
     */
    private function editor(string|array|null $permission = null): User
    {
        $editor = User::factory()->create()->assignRole('editor');

        if ($permission !== null) {
            $editor->givePermissionTo($permission);
        }

        return $editor;
    }

    /**
     * @return array{title: string, body: string, status: string}
     */
    private function postPayload(): array
    {
        return [
            'title' => 'A permitted post',
            'body' => '<p>Hello</p>',
            'status' => 'draft',
        ];
    }
}
