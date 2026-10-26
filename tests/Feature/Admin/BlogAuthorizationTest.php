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

    private function editor(?string $permission = null): User
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
