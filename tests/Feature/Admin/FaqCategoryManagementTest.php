<?php

namespace Tests\Feature\Admin;

use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class FaqCategoryManagementTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsAdmin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'admin']);
        $user->assignRole($role);

        $this->actingAs($user);

        return $user;
    }

    public function test_admin_can_view_faq_category_index_page(): void
    {
        $this->actingAsAdmin();

        FaqCategory::factory()->create(['name' => 'Billing', 'slug' => 'billing', 'order' => 0]);
        FaqCategory::factory()->create(['name' => 'Account', 'slug' => 'account', 'order' => 1]);

        $response = $this->get(route('acp.support.faq-categories.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/SupportFaqCategories')
            ->has('categories', 2)
            ->where('categories.0.name', 'Billing')
            ->where('categories.1.name', 'Account')
        );
    }

    public function test_faq_category_index_returns_json_payload(): void
    {
        $this->actingAsAdmin();

        $category = FaqCategory::factory()->create(['name' => 'Getting Started', 'slug' => 'getting-started']);
        Faq::factory()->for($category, 'category')->create();

        $response = $this->getJson(route('acp.support.faq-categories.index'));

        $response->assertOk()
            ->assertJsonPath('categories.0.name', 'Getting Started')
            ->assertJsonPath('categories.0.faqs_count', 1);
    }

    public function test_admin_can_create_faq_category(): void
    {
        $this->actingAsAdmin();

        $response = $this->post(route('acp.support.faq-categories.store'), [
            'name' => 'Troubleshooting',
            'slug' => '',
            'description' => 'Steps for solving common issues.',
            'order' => 1,
        ]);

        $response->assertRedirect(route('acp.support.faq-categories.index'));

        $this->assertDatabaseHas('faq_categories', [
            'name' => 'Troubleshooting',
            'slug' => 'troubleshooting',
            'description' => 'Steps for solving common issues.',
            'order' => 1,
        ]);
    }

    public function test_faq_category_slug_must_be_unique(): void
    {
        $this->actingAsAdmin();

        FaqCategory::factory()->create(['slug' => 'duplicate']);

        $response = $this->from(route('acp.support.faq-categories.create'))
            ->post(route('acp.support.faq-categories.store'), [
                'name' => 'Another Category',
                'slug' => 'duplicate',
                'description' => 'Should not save',
                'order' => 0,
            ]);

        $response->assertRedirect(route('acp.support.faq-categories.create'));
        $response->assertSessionHasErrors('slug');
    }

    public function test_admin_can_update_faq_category(): void
    {
        $this->actingAsAdmin();

        $category = FaqCategory::factory()->create([
            'name' => 'Original Category',
            'slug' => 'original-category',
            'description' => 'Original description',
            'order' => 5,
        ]);

        $response = $this->put(route('acp.support.faq-categories.update', $category), [
            'name' => 'Updated Category',
            'slug' => '',
            'description' => 'Updated description',
            'order' => 7,
        ]);

        $response->assertRedirect(route('acp.support.faq-categories.index'));

        $category->refresh();

        $this->assertSame('Updated Category', $category->name);
        $this->assertSame('updated-category', $category->slug);
        $this->assertSame('Updated description', $category->description);
        $this->assertSame(7, $category->order);
    }

    public function test_admin_can_reuse_slug_on_same_faq_category(): void
    {
        $this->actingAsAdmin();

        $category = FaqCategory::factory()->create([
            'name' => 'Announcements',
            'slug' => 'announcements',
        ]);

        $response = $this->put(route('acp.support.faq-categories.update', $category), [
            'name' => 'Announcements',
            'slug' => 'announcements',
            'description' => null,
            'order' => $category->order,
        ]);

        $response->assertRedirect(route('acp.support.faq-categories.index'));

        $this->assertDatabaseHas('faq_categories', [
            'id' => $category->id,
            'slug' => 'announcements',
        ]);
    }

    public function test_admin_can_delete_faq_category(): void
    {
        $this->actingAsAdmin();

        $category = FaqCategory::factory()->create();
        Faq::factory()->count(2)->for($category, 'category')->create();

        $response = $this->from(route('acp.support.faq-categories.index'))
            ->delete(route('acp.support.faq-categories.destroy', $category));

        $response->assertRedirect(route('acp.support.faq-categories.index'));

        $this->assertDatabaseMissing('faq_categories', ['id' => $category->id]);
        $this->assertDatabaseMissing('faqs', ['faq_category_id' => $category->id]);
    }
}
