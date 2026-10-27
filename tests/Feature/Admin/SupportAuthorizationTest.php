<?php

namespace Tests\Feature\Admin;

use App\Models\Faq;
use App\Models\FaqCategory;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('editor');
        Role::findOrCreate('moderator');

        foreach ([
            'view',
            'create',
            'edit',
            'delete',
            'assign',
            'move',
            'publish',
            'priority',
            'reply',
            'status',
        ] as $ability) {
            Permission::findOrCreate('support.acp.'.$ability);
        }

        foreach (['support_templates', 'support_teams', 'support_assignment_rules'] as $area) {
            Permission::findOrCreate($area.'.acp.view');
        }
    }

    #[Test]
    public function an_editor_cannot_open_or_change_support_without_a_support_permission(): void
    {
        $editor = User::factory()->create()->assignRole('editor');
        $ticket = $this->ticket();

        $this->actingAs($editor)->get(route('acp.support.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.support.tickets.show', $ticket))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.support.templates.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.support.teams.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.support.sla.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.support.assignment-rules.index'))->assertForbidden();
        $this->actingAs($editor)->get(route('acp.support.ticket-categories.index'))->assertForbidden();
        $this->actingAs($editor)->delete(route('acp.support.tickets.destroy', $ticket))->assertForbidden();
        $this->actingAs($editor)->put(route('acp.support.tickets.status', $ticket), [
            'status' => 'closed',
        ])->assertForbidden();

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertNotNull($ticket->fresh());
    }

    #[Test]
    public function a_moderator_with_view_can_open_the_desk_and_cannot_change_a_ticket(): void
    {
        $moderator = User::factory()->create()->assignRole('moderator');
        $moderator->givePermissionTo('support.acp.view');
        $ticket = $this->ticket();

        $this->actingAs($moderator)->get(route('acp.support.index'))->assertOk();
        $this->actingAs($moderator)->get(route('acp.support.tickets.show', $ticket))->assertOk();
        $this->actingAs($moderator)->get(route('acp.support.ticket-categories.index'))->assertForbidden();
        $this->actingAs($moderator)->put(route('acp.support.tickets.status', $ticket), [
            'status' => 'pending',
        ])->assertForbidden();
        $this->actingAs($moderator)->put(route('acp.support.tickets.assign', $ticket), [
            'assigned_to' => $moderator->id,
        ])->assertForbidden();

        $this->assertSame('open', $ticket->fresh()->status);
        $this->assertNull($ticket->fresh()->assigned_to);
    }

    #[Test]
    public function saving_a_ticket_cannot_change_status_priority_or_assignee_without_those_permissions(): void
    {
        $editor = User::factory()->create()->assignRole('editor');
        $editor->givePermissionTo('support.acp.edit');
        $requester = User::factory()->create();
        $ticket = $this->ticket();
        $agent = User::factory()->create();

        $this->actingAs($editor)->put(route('acp.support.tickets.update', $ticket), [
            'subject' => 'Renamed ticket',
            'status' => 'open',
            'priority' => 'medium',
            'assigned_to' => null,
            'user_id' => $requester->id,
        ])->assertRedirect();

        $ticket->refresh();
        $this->assertSame('Renamed ticket', $ticket->subject);
        $this->assertSame($requester->id, $ticket->user_id);

        $this->actingAs($editor)->put(route('acp.support.tickets.update', $ticket), [
            'status' => 'closed',
        ])->assertForbidden();
        $this->actingAs($editor)->put(route('acp.support.tickets.update', $ticket), [
            'priority' => 'high',
        ])->assertForbidden();
        $this->actingAs($editor)->put(route('acp.support.tickets.update', $ticket), [
            'assigned_to' => $agent->id,
        ])->assertForbidden();

        $ticket->refresh();
        $this->assertSame('open', $ticket->status);
        $this->assertSame('medium', $ticket->priority);
        $this->assertNull($ticket->assigned_to);
    }

    #[Test]
    public function status_allows_a_status_change_and_create_can_open_categories(): void
    {
        $ticket = $this->ticket();

        $statusEditor = User::factory()->create()->assignRole('editor');
        $statusEditor->givePermissionTo(['support.acp.edit', 'support.acp.status']);

        $this->actingAs($statusEditor)->put(route('acp.support.tickets.update', $ticket), [
            'status' => 'pending',
        ])->assertRedirect();
        $this->assertSame('pending', $ticket->fresh()->status);

        $creator = User::factory()->create()->assignRole('editor');
        $creator->givePermissionTo('support.acp.create');

        $this->actingAs($creator)->get(route('acp.support.ticket-categories.index'))->assertOk();
        $this->actingAs($creator)->get(route('acp.support.faq-categories.index'))->assertOk();
        $this->actingAs($creator)->get(route('acp.support.index'))->assertForbidden();
    }

    #[Test]
    public function saving_an_faq_cannot_publish_or_reorder_it_without_those_permissions(): void
    {
        $creator = User::factory()->create()->assignRole('editor');
        $creator->givePermissionTo('support.acp.create');
        $category = FaqCategory::create([
            'name' => 'Billing',
            'slug' => 'billing',
        ]);

        $this->actingAs($creator)->post(route('acp.support.faqs.store'), [
            'faq_category_id' => $category->id,
            'question' => 'How do I reset a password?',
            'answer' => 'Use the reset form on the sign-in page.',
            'published' => true,
        ])->assertForbidden();
        $this->assertDatabaseMissing('faqs', ['question' => 'How do I reset a password?']);

        $this->actingAs($creator)->post(route('acp.support.faqs.store'), [
            'faq_category_id' => $category->id,
            'question' => 'Where is the invoice?',
            'answer' => 'Invoices are on the billing page.',
            'published' => false,
        ])->assertRedirect();
        $this->assertDatabaseHas('faqs', [
            'question' => 'Where is the invoice?',
            'published' => false,
        ]);

        $editor = User::factory()->create()->assignRole('editor');
        $editor->givePermissionTo('support.acp.edit');
        $faq = Faq::create([
            'faq_category_id' => $category->id,
            'question' => 'Old question',
            'answer' => 'Old answer',
            'order' => 0,
            'published' => false,
        ]);

        $this->actingAs($editor)->put(route('acp.support.faqs.update', $faq), [
            'published' => true,
        ])->assertForbidden();
        $this->actingAs($editor)->put(route('acp.support.faqs.update', $faq), [
            'order' => 2,
        ])->assertForbidden();
        $this->actingAs($editor)->put(route('acp.support.faqs.update', $faq), [
            'question' => 'New question',
            'published' => false,
            'order' => 0,
        ])->assertRedirect();

        $faq->refresh();
        $this->assertSame('New question', $faq->question);
        $this->assertFalse((bool) $faq->published);
        $this->assertSame(0, (int) $faq->order);
    }

    private function ticket(): SupportTicket
    {
        return SupportTicket::create([
            'user_id' => User::factory()->create()->id,
            'subject' => 'Login issue',
            'body' => 'Cannot sign in.',
            'status' => 'open',
            'priority' => 'medium',
        ]);
    }
}
