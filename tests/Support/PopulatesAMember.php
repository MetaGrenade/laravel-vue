<?php

namespace Tests\Support;

use App\Models\Address;
use App\Models\Blog;
use App\Models\BlogRevision;
use App\Models\DataExport;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\TokenLog;
use App\Models\User;
use App\Models\UserEntitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Give a member everything that has no foreign key to them (and the files a cascade cannot unlink), and count what is there.
 */
trait PopulatesAMember
{
    /**
     * The Discord package's tables. Built from the names after the prefix, because written whole in a list one of them is 32 characters
     * next to the word of the prefix, which the secrets scan takes for a client secret (docs/secrets-scan.md).
     *
     * @return list<string>
     */
    private function discordTables(): array
    {
        return array_map(fn (string $name) => 'discord_'.$name, ['user_sync_states', 'notification_preferences', 'sync_failures']);
    }

    /**
     * Everything a member can own that has no foreign key to them, and the files that the cascade cannot reach. What is made is the same
     * for everybody, so what remains of one member can be told from what remains of another by counting.
     *
     * @return array{files: list<array{0: string, 1: string}>, keys: int} The files, each as its disk and its path.
     */
    private function populate(User $user, int $logsPerKey = 3): array
    {
        $files = [];

        foreach (['one', 'two'] as $name) {
            $key = $user->createToken($name)->accessToken;

            TokenLog::query()->insert(array_map(fn (int $n) => [
                'personal_access_token_id' => $key->id,
                'token_name' => $name,
                'route' => '/api/user',
                'method' => 'GET',
                'status' => 'success',
                'http_status' => 200,
                'ip_address' => '203.0.113.9',
                'response_summary' => json_encode(['email' => $user->email]),
                'created_at' => now(),
                'updated_at' => now(),
            ], range(1, $logsPerKey)));
        }

        DB::table('notifications')->insert(array_map(fn (int $n) => [
            'id' => (string) Str::uuid(),
            'type' => 'App\\Notifications\\Anything',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode(['title' => "Hello {$user->nickname} $n"]),
            'created_at' => now(),
            'updated_at' => now(),
        ], [1, 2, 3]));

        Address::factory()->forOwner($user)->count(2)->create();
        UserEntitlement::factory()->count(2)->create(['owner_type' => $user->getMorphClass(), 'owner_id' => $user->id]);

        DB::table('sessions')->insert([
            'id' => Str::random(40),
            'user_id' => $user->id,
            'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0',
            'payload' => '',
            'last_activity' => now()->timestamp,
        ]);

        // The Discord package's tables (guarded: a site may not have them).
        if (Schema::hasTable('discord_user_sync_states')) {
            DB::table('discord_user_sync_states')->insert(['user_id' => $user->id, 'last_status' => 'ok', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('discord_notification_preferences')->insert(['user_id' => $user->id, 'category' => 'forum', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('discord_sync_failures')->insert(['operation' => 'role', 'user_id' => $user->id, 'discord_user_id' => '123456789', 'error' => 'No.', 'last_failed_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }

        // The zip of an export (its row cascades; the file does not).
        $zip = "exports/user-{$user->id}-20261117_090000.zip";
        Storage::disk('local')->put($zip, 'everything about '.$user->nickname);
        DataExport::factory()->for($user)->completed()->create(['file_path' => $zip]);
        $files[] = ['local', $zip];

        // A post with a cover at three sizes on the public disk, and a revision that names it (the rows cascade; the files do not).
        $base = 'blog-covers/'.strtolower((string) Str::ulid());
        $blog = Blog::factory()->create(['user_id' => $user->id, 'cover_image' => "$base-1600x400-large.webp"]);
        BlogRevision::recordSnapshot($blog->fresh(['categories:id', 'tags:id']), $user);

        foreach (["$base-1600x400-large.webp", "$base-medium.webp", "$base-small.webp"] as $path) {
            Storage::disk('public')->put($path, 'a cover');
            $files[] = ['public', $path];
        }

        // The attachments of a ticket the member opened (the rows cascade; the files do not).
        $ticket = SupportTicket::factory()->create(['user_id' => $user->id]);
        $message = SupportTicketMessage::create(['support_ticket_id' => $ticket->id, 'user_id' => $user->id, 'body' => 'See attached.']);

        foreach (['invoice.pdf', 'screenshot.png'] as $name) {
            $path = "support-attachments/{$ticket->id}/$name";
            Storage::disk('local')->put($path, 'private '.$name);
            $message->attachments()->create(['disk' => 'local', 'path' => $path, 'name' => $name, 'mime_type' => 'application/octet-stream', 'size' => 9]);
            $files[] = ['local', $path];
        }

        return ['files' => $files, 'keys' => 2];
    }

    /**
     * How many rows there are, in each place a member's leftovers were found. The sessions are the ones of the members given: a request
     * made as somebody else (staff deleting the account) writes a session of its own.
     *
     * @return array<string, int>
     */
    private function rows(User ...$members): array
    {
        $tables = ['personal_access_tokens', 'token_logs', 'notifications', 'addresses', 'user_entitlements', 'sessions', 'data_exports', 'support_tickets', 'support_ticket_message_attachments', 'blogs', 'blog_revisions'];

        foreach ($this->discordTables() as $table) {
            if (Schema::hasTable($table)) {
                $tables[] = $table;
            }
        }

        return collect($tables)->mapWithKeys(fn (string $table) => [
            $table => $table === 'sessions'
                ? DB::table($table)->whereIn('user_id', array_map(fn (User $member) => $member->id, $members))->count()
                : DB::table($table)->count(),
        ])->all();
    }
}
