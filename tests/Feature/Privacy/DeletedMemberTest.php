<?php

namespace Tests\Feature\Privacy;

use App\Models\Address;
use App\Models\BillingInvoice;
use App\Models\DataExport;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\TokenLog;
use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Support\PopulatesAMember;
use Tests\TestCase;

/**
 * When a member's account is deleted, what has no foreign key to it, and the files the database cannot unlink, go too.
 *
 * Most of a member's rows have `user_id ON DELETE CASCADE`. These did not: the API keys and every request made with them (address,
 * fields sent, the answers), notifications, saved addresses, entitlements (all morphs), browser sessions, the Discord package's rows,
 * and, as files, the zip of a data export and the attachments of the member's tickets (a cascade fires no model event, so the
 * `deleting` hooks that unlink files never ran). After `$user->delete()` every one of them was still there.
 */
class DeletedMemberTest extends TestCase
{
    use PopulatesAMember;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');

        // Where sessions are kept in the database, a deleted member's are deleted with them (the tests run on `array`).
        config(['session.driver' => 'database']);
    }

    private function admin(): User
    {
        Role::findOrCreate('admin', 'web');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        return $admin;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function waysToDeleteAMember(): array
    {
        return [
            'the model' => ['model'],
            'the member, from their settings' => ['member'],
            'staff, from the admin area' => ['staff'],
        ];
    }

    private function deleteMember(string $how, User $member): void
    {
        match ($how) {
            'model' => $member->delete(),
            'member' => $this->actingAs($member)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect('/'),
            'staff' => $this->actingAs($this->admin())->delete(route('acp.users.destroy', $member))->assertSessionHas('success', 'User deleted.'),
        };

        $this->assertNull($member->fresh(), 'the account is gone');
    }

    #[Test]
    #[DataProvider('waysToDeleteAMember')]
    public function nothing_a_member_owned_is_left_and_nobody_else_loses_anything(string $how): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();

        $mine = $this->populate($member);
        $theirs = $this->populate($other);

        $everything = $this->rows($member, $other);
        $this->deleteMember($how, $member);
        $after = $this->rows($member, $other);

        foreach ($everything as $table => $count) {
            $this->assertSame($count / 2, $after[$table], "$table: only the other member's rows remain (a staff member made for the test owns none)");
        }

        foreach ($mine['files'] as [$disk, $path]) {
            Storage::disk($disk)->assertMissing($path);
        }
        foreach ($theirs['files'] as [$disk, $path]) {
            Storage::disk($disk)->assertExists($path);
        }
    }

    #[Test]
    public function more_rows_than_one_chunk_are_all_deleted(): void
    {
        $member = User::factory()->create();
        $key = $member->createToken('busy')->accessToken;

        foreach (array_chunk(range(1, 5200), 500) as $chunk) {
            TokenLog::query()->insert(array_map(fn (int $n) => [
                'personal_access_token_id' => $key->id,
                'token_name' => 'busy',
                'route' => '/api/user',
                'method' => 'GET',
                'status' => 'success',
                'http_status' => 200,
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }

        // The notifications have no foreign key to fall back on: only the loop deletes them all.
        foreach (array_chunk(range(1, 5200), 500) as $chunk) {
            DB::table('notifications')->insert(array_map(fn (int $n) => [
                'id' => (string) Str::uuid(),
                'type' => 'x',
                'notifiable_type' => $member->getMorphClass(),
                'notifiable_id' => $member->id,
                'data' => '{}',
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }

        $this->assertSame(5200, TokenLog::count());
        $this->assertSame(5200, DB::table('notifications')->count());

        $member->delete();

        $this->assertSame(0, TokenLog::count());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
    }

    #[Test]
    public function rows_that_belong_to_something_else_with_the_same_id_are_kept(): void
    {
        // The records name an owner by type and id, and a team may own them in 1.1: a team that has the member's id is not the member.
        $member = User::factory()->create();

        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'x', 'notifiable_type' => 'team', 'notifiable_id' => $member->id, 'data' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        Address::factory()->create(['owner_type' => 'team', 'owner_id' => $member->id]);
        UserEntitlement::factory()->create(['owner_type' => 'team', 'owner_id' => $member->id]);
        DB::table('personal_access_tokens')->insert(['tokenable_type' => 'team', 'tokenable_id' => $member->id, 'name' => 'team key', 'token' => hash('sha256', 'team'), 'abilities' => '["*"]', 'created_at' => now(), 'updated_at' => now()]);

        $member->delete();

        foreach (['notifications', 'addresses', 'user_entitlements', 'personal_access_tokens'] as $table) {
            $this->assertSame(1, DB::table($table)->count(), "$table: the team's row stays");
        }
    }

    #[Test]
    public function the_records_of_money_are_kept(): void
    {
        // Orders, payments and invoices are what tax and accounting law make a site keep (the starting privacy text says so, section 5).
        $member = User::factory()->create();
        $order = Order::factory()->forUser($member)->paid()->create();
        $invoice = BillingInvoice::factory()->create(['user_id' => $member->id]);

        $member->delete();

        $this->assertNotNull(Order::query()->find($order->id), 'the order stays');
        $this->assertNotNull(BillingInvoice::query()->find($invoice->id), 'the invoice stays');
    }

    #[Test]
    public function an_export_row_that_names_another_file_does_not_take_that_file(): void
    {
        // Staff could once save any path (DataExport::namesAnExportFile()): the file such a row names is not the member's to take.
        $member = User::factory()->create();
        Storage::disk('local')->put('products/12/game.zip', 'a product');
        DataExport::factory()->for($member)->completed()->create(['file_path' => 'products/12/game.zip']);

        $member->delete();

        Storage::disk('local')->assertExists('products/12/game.zip');
    }

    #[Test]
    public function what_a_member_of_staff_attached_to_somebody_elses_ticket_stays_with_that_ticket(): void
    {
        $staff = User::factory()->create();
        $customer = User::factory()->create();
        $ticket = SupportTicket::factory()->create(['user_id' => $customer->id]);
        $reply = SupportTicketMessage::create(['support_ticket_id' => $ticket->id, 'user_id' => $staff->id, 'body' => 'Here is the guide.']);

        Storage::disk('local')->put('support-attachments/guide.pdf', 'the guide');
        $reply->attachments()->create(['disk' => 'local', 'path' => 'support-attachments/guide.pdf', 'name' => 'guide.pdf', 'mime_type' => 'application/pdf', 'size' => 9]);

        $staff->delete();

        Storage::disk('local')->assertExists('support-attachments/guide.pdf');
        $this->assertSame(1, DB::table('support_ticket_message_attachments')->count());
    }

    #[Test]
    public function a_disk_that_cannot_be_reached_does_not_undo_the_deletion_or_stop_the_rest(): void
    {
        Exceptions::fake();

        $member = User::factory()->create();
        $mine = $this->populate($member);

        // An attachment on a disk the site has no configuration for: it throws when asked for.
        $ticket = SupportTicket::query()->where('user_id', $member->id)->firstOrFail();
        $message = SupportTicketMessage::query()->where('support_ticket_id', $ticket->id)->firstOrFail();
        $message->attachments()->create(['disk' => 'nowhere', 'path' => 'x/y.txt', 'name' => 'y.txt', 'mime_type' => 'text/plain', 'size' => 1]);

        $member->delete();

        $this->assertNull($member->fresh(), 'the account is gone');
        Exceptions::assertReported(InvalidArgumentException::class);

        foreach ($mine['files'] as [$disk, $path]) {
            Storage::disk($disk)->assertMissing($path);
        }
        $this->assertSame(0, DB::table('personal_access_tokens')->count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    #[Test]
    public function sessions_of_a_site_that_keeps_them_elsewhere_are_left_alone(): void
    {
        config(['session.driver' => 'file']);

        $member = User::factory()->create();
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $member->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'x', 'payload' => '', 'last_activity' => now()->timestamp]);

        $member->delete();

        $this->assertSame(1, DB::table('sessions')->count(), 'a table the site does not use for sessions is not touched');
    }
}
