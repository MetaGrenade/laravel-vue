<?php

namespace Tests\Feature\Admin;

use App\Models\SupportTicket;
use App\Models\SupportTicketCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\Fluent\AssertableJson;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportTicketCategoryManagementTest extends TestCase
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

    public function test_admin_can_view_ticket_category_index_page(): void
    {
        $this->actingAsAdmin();

        SupportTicketCategory::factory()->create(['name' => 'Billing']);
        SupportTicketCategory::factory()->create(['name' => 'General']);

        $response = $this->get(route('acp.support.ticket-categories.index'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('acp/SupportTicketCategories')
            ->has('categories', 2)
            ->where('categories.0.name', 'Billing')
            ->where('categories.1.name', 'General')
        );
    }

    public function test_ticket_category_index_returns_json_payload(): void
    {
        $this->actingAsAdmin();

        $category = SupportTicketCategory::factory()->create(['name' => 'Technical']);

        $response = $this->getJson(route('acp.support.ticket-categories.index'));

        $response->assertOk()
            ->assertJson(fn (AssertableJson $json) => $json
                ->where('categories', fn ($categories) => collect($categories)
                    ->contains(fn (array $payload) => $payload['name'] === $category->name)
                )
            );
    }

    public function test_admin_can_create_ticket_category(): void
    {
        $this->actingAsAdmin();

        $response = $this->post(route('acp.support.ticket-categories.store'), [
            'name' => 'Account Support',
        ]);

        $response->assertRedirect(route('acp.support.ticket-categories.index'));

        $this->assertDatabaseHas('support_ticket_categories', [
            'name' => 'Account Support',
        ]);
    }

    public function test_ticket_category_name_must_be_unique(): void
    {
        $this->actingAsAdmin();

        SupportTicketCategory::factory()->create(['name' => 'General Support']);

        $response = $this->from(route('acp.support.ticket-categories.create'))
            ->post(route('acp.support.ticket-categories.store'), [
                'name' => 'General Support',
            ]);

        $response->assertRedirect(route('acp.support.ticket-categories.create'));
        $response->assertSessionHasErrors('name');
    }

    public function test_admin_can_update_ticket_category(): void
    {
        $this->actingAsAdmin();

        $category = SupportTicketCategory::factory()->create([
            'name' => 'Legacy Support',
        ]);

        $response = $this->put(route('acp.support.ticket-categories.update', $category), [
            'name' => 'Priority Support',
        ]);

        $response->assertRedirect(route('acp.support.ticket-categories.index'));

        $this->assertDatabaseHas('support_ticket_categories', [
            'id' => $category->id,
            'name' => 'Priority Support',
        ]);
    }

    public function test_admin_can_reuse_ticket_category_name_on_same_record(): void
    {
        $this->actingAsAdmin();

        $category = SupportTicketCategory::factory()->create([
            'name' => 'Announcements',
        ]);

        $response = $this->put(route('acp.support.ticket-categories.update', $category), [
            'name' => 'Announcements',
        ]);

        $response->assertRedirect(route('acp.support.ticket-categories.index'));

        $this->assertDatabaseHas('support_ticket_categories', [
            'id' => $category->id,
            'name' => 'Announcements',
        ]);
    }

    public function test_admin_can_delete_ticket_category(): void
    {
        $admin = $this->actingAsAdmin();

        $category = SupportTicketCategory::factory()->create();

        $ticket = SupportTicket::create([
            'user_id' => $admin->id,
            'subject' => 'Test ticket',
            'body' => 'Example body',
            'status' => 'open',
            'priority' => 'low',
            'support_ticket_category_id' => $category->id,
        ]);

        $response = $this->from(route('acp.support.ticket-categories.index'))
            ->delete(route('acp.support.ticket-categories.destroy', $category));

        $response->assertRedirect(route('acp.support.ticket-categories.index'));

        $this->assertDatabaseMissing('support_ticket_categories', ['id' => $category->id]);
        $this->assertDatabaseHas('support_tickets', [
            'id' => $ticket->id,
            'support_ticket_category_id' => null,
        ]);
    }
}
