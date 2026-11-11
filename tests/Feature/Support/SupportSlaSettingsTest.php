<?php

namespace Tests\Feature\Support;

use App\Jobs\MonitorSupportTicketSlas;
use App\Models\SupportAssignmentRule;
use App\Models\SupportTicket;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\SupportSlaConfiguration;
use App\Support\SupportTicketAuditor;
use App\Support\SupportTicketAutoAssigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Admin → Support → SLA takes a time as text ("2 days", "90 minutes") for when a ticket is raised a priority and when it is handed to
 * someone else. Text that is not a time must be refused with a message on the field. Since PHP 8.3 `DateInterval::createFromDateString()`
 * throws on text it cannot read instead of returning false, so the check that was written for `false` never ran: typing "banana" was a
 * server error, and a value that got stored some other way stopped the job that applies the thresholds. docs/support.md.
 */
class SupportSlaSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        SupportAssignmentRule::flushCache();
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $admin->assignRole(Role::findOrCreate('admin'));

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function form(array $overrides = []): array
    {
        return array_replace_recursive([
            'priority_escalations' => [
                'low' => ['after' => '2 days', 'to' => 'medium'],
                'medium' => ['after' => '1 day', 'to' => 'high'],
                'high' => ['after' => null, 'to' => null],
            ],
            'reassign_after' => ['low' => '6 hours', 'medium' => '3 hours', 'high' => '1 hour'],
        ], $overrides);
    }

    private function runTheJob(): void
    {
        (new MonitorSupportTicketSlas)->handle(app(SupportTicketAutoAssigner::class), app(SupportTicketAuditor::class));
    }

    // --- The form ---------------------------------------------------------------------------------------

    #[Test]
    public function times_that_can_be_read_are_saved(): void
    {
        $this->actingAs($this->admin())->put(route('acp.support.sla.update'), $this->form())
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('acp.support.sla.index'));

        $this->assertSame('2 days', SupportSlaConfiguration::priorityEscalations()['low']['after']);
        $this->assertSame('3 hours', SupportSlaConfiguration::reassignAfter()['medium']);
    }

    #[Test]
    public function text_that_is_not_a_time_for_an_escalation_is_refused_with_a_message_on_the_field(): void
    {
        $this->actingAs($this->admin())->put(route('acp.support.sla.update'), $this->form([
            'priority_escalations' => ['low' => ['after' => 'banana']],
        ]))
            ->assertSessionHasErrors('priority_escalations.low.after');

        $this->assertStringContainsString('valid relative time expression', session('errors')->first('priority_escalations.low.after'));

        $this->assertNull(SystemSetting::get(SupportSlaConfiguration::SETTING_KEY), 'nothing was saved');
    }

    #[Test]
    public function text_that_is_not_a_time_for_a_reassignment_is_refused_with_a_message_on_the_field(): void
    {
        $this->actingAs($this->admin())->put(route('acp.support.sla.update'), $this->form([
            'reassign_after' => ['high' => 'soon-ish'],
        ]))
            ->assertSessionHasErrors('reassign_after.high');

        $this->assertNull(SystemSetting::get(SupportSlaConfiguration::SETTING_KEY), 'nothing was saved');
    }

    #[Test]
    public function an_empty_field_is_still_allowed_and_means_no_rule(): void
    {
        $this->actingAs($this->admin())->put(route('acp.support.sla.update'), $this->form([
            'priority_escalations' => ['low' => ['after' => '', 'to' => '']],
            'reassign_after' => ['low' => ''],
        ]))
            ->assertSessionHasNoErrors();

        $this->assertNull(SupportSlaConfiguration::priorityEscalations()['low']['after']);
    }

    // --- The job ------------------------------------------------------------------------------------------

    #[Test]
    public function a_stored_time_that_cannot_be_read_is_skipped_and_the_other_rules_still_run(): void
    {
        // Not possible through the form any more, but a value can be in the table from before the check, from an import or from a hand edit.
        SystemSetting::set(SupportSlaConfiguration::SETTING_KEY, [
            'priority_escalations' => [
                'low' => ['after' => 'banana', 'to' => 'high'],
                'medium' => ['after' => '1 hour', 'to' => 'high'],
                'high' => ['after' => null, 'to' => null],
            ],
            'reassign_after' => ['low' => null, 'medium' => null, 'high' => null],
        ]);

        $broken = SupportTicket::factory()->create(['priority' => 'low', 'status' => 'open', 'created_at' => Carbon::now()->subDays(30)]);
        $fine = SupportTicket::factory()->create(['priority' => 'medium', 'status' => 'open', 'created_at' => Carbon::now()->subDays(2)]);

        $this->runTheJob();

        $this->assertSame('low', $broken->fresh()->priority, 'a rule that cannot be read does not raise every ticket at once');
        $this->assertSame('high', $fine->fresh()->priority, 'and does not stop the rules that can be read');
    }

    #[Test]
    public function a_time_that_cannot_be_read_is_said_once_in_the_log_however_many_tickets_there_are(): void
    {
        SystemSetting::set(SupportSlaConfiguration::SETTING_KEY, [
            'priority_escalations' => [
                'low' => ['after' => 'banana', 'to' => 'high'],
                'medium' => ['after' => null, 'to' => null],
                'high' => ['after' => null, 'to' => null],
            ],
            'reassign_after' => ['low' => null, 'medium' => null, 'high' => null],
        ]);

        SupportTicket::factory()->count(3)->create(['priority' => 'low', 'status' => 'open', 'created_at' => Carbon::now()->subDays(30)]);

        Log::spy();

        $this->runTheJob();

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context) => $context === ['value' => 'banana']);
    }

    #[Test]
    public function a_stored_reassignment_time_that_cannot_be_read_hands_nobody_over(): void
    {
        SystemSetting::set(SupportSlaConfiguration::SETTING_KEY, [
            'priority_escalations' => [
                'low' => ['after' => null, 'to' => null],
                'medium' => ['after' => null, 'to' => null],
                'high' => ['after' => null, 'to' => null],
            ],
            'reassign_after' => ['low' => 'banana', 'medium' => null, 'high' => null],
        ]);

        $primary = User::factory()->create();
        $backup = User::factory()->create();
        SupportAssignmentRule::create(['priority' => 'low', 'assigned_to' => $primary->id, 'position' => 0]);
        SupportAssignmentRule::create(['priority' => null, 'assigned_to' => $backup->id, 'position' => 1]);

        $ticket = SupportTicket::factory()->create(['priority' => 'low', 'status' => 'open']);
        app(SupportTicketAutoAssigner::class)->assign($ticket);
        $ticket->forceFill(['updated_at' => Carbon::now()->subDays(30)])->saveQuietly();

        $this->runTheJob();

        $this->assertSame($primary->id, $ticket->fresh()->assigned_to);
    }
}
