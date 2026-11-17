<?php

namespace App\Support\Privacy;

use App\Models\Blog;
use App\Models\DataExport;
use App\Models\SupportTicket;
use App\Models\SupportTicketMessage;
use App\Models\SupportTicketMessageAttachment;
use App\Models\TokenLog;
use App\Models\User;
use App\Support\Blog\BlogCovers;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * What a member's account leaves behind when it is deleted, that the database does not take with it.
 *
 * Most of what a member owns goes by itself: their tables have `user_id` with `ON DELETE CASCADE` (posts, comments, tickets, exports,
 * follows, votes, usage...). Two kinds of thing do not:
 *
 *  - rows with no foreign key to the user, because they belong to a morph (`tokenable`, `notifiable`, `owner`) or to a package: API
 *    keys and the log of every request made with them (the address, the fields sent, the answers), notifications, the address book,
 *    entitlements, browser sessions, and the Discord rows of the Discord package;
 *  - files on a disk. A cascade runs inside the database and fires no model event, so the `deleting` hooks that unlink a file never
 *    run: the zip of a data export, the attachments of the member's support tickets and the covers of the posts they wrote stayed for
 *    good (the forum's attachments are handled in User::booted() already).
 *
 * `filesOf()` has to run BEFORE the account is deleted, because the cascade takes the rows that name the files with it; `forget()`
 * runs after. See docs/account-deletion.md for what is kept on purpose (orders, payments, invoices, subscriptions, the audit log and
 * moderation records) and why.
 */
class MemberLeftovers
{
    /**
     * The Discord package's tables, by their names after the prefix, which `discordTables()` puts back. Written whole in a list, one of
     * them is 32 characters next to the word of the prefix, and the secrets scan takes that for a client secret (docs/secrets-scan.md).
     */
    private const DISCORD_TABLE_NAMES = ['user_sync_states', 'notification_preferences', 'sync_failures'];

    /** Rows are deleted this many at a time, so that a member with a great many does not hold a lock on the table for the whole of it. */
    private const CHUNK = 5000;

    /**
     * The files that go with the account: where they are, before the account (and with it the rows that name them) is gone.
     *
     * @return list<array{disk: string, path: string}>
     */
    public function filesOf(User $user): array
    {
        $files = [];

        // Only a file of the exports directory: a row can name any file on the private disk if staff saved it before they were held to
        // that, and that file is not the member's (DataExport::namesAnExportFile()).
        foreach (DataExport::query()->where('user_id', $user->getKey())->whereNotNull('file_path')->cursor() as $export) {
            if ($export->namesAnExportFile()) {
                $files[] = ['disk' => 'local', 'path' => (string) $export->file_path];
            }
        }

        // The attachments of the member's own tickets, which go with them (SupportTicket::deleting() would unlink them one by one;
        // a cascade does not run it). What the member wrote on somebody else's ticket stays with that ticket.
        $messages = SupportTicketMessage::query()
            ->select('id')
            ->whereIn('support_ticket_id', SupportTicket::query()->select('id')->where('user_id', $user->getKey()));

        foreach (SupportTicketMessageAttachment::query()->whereIn('support_ticket_message_id', $messages)->cursor() as $attachment) {
            if ($attachment->disk && $attachment->path) {
                $files[] = ['disk' => (string) $attachment->disk, 'path' => (string) $attachment->path];
            }
        }

        // The covers of the posts the member wrote, which go with them (Blog::deleting() would remove them one post at a time; a
        // cascade does not run it). They are on the public disk, at every size.
        $covers = app(BlogCovers::class);

        foreach ($covers->pathsOfPosts(Blog::query()->where('user_id', $user->getKey())->select('id')) as $path) {
            foreach ($covers->filesOf($path) as $file) {
                $files[] = ['disk' => 'public', 'path' => $file];
            }
        }

        return $files;
    }

    /**
     * Delete what the account leaves behind: the files `filesOf()` found, and the rows no foreign key takes. Call it once the account is gone.
     *
     * A disk that cannot be reached does not undo a deletion that has happened, so each failure is reported and the rest goes on.
     *
     * @param  list<array{disk: string, path: string}>  $files
     */
    public function forget(User $user, array $files): void
    {
        foreach ($files as $file) {
            try {
                // A disk set not to throw (the public one is) says `false` for a file it could not delete.
                if (! Storage::disk($file['disk'])->delete($file['path'])) {
                    report(new RuntimeException("The file {$file['path']} on the {$file['disk']} disk could not be deleted."));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $this->forgetApiKeys($user);

        $this->inChunks(DB::table('notifications')
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey()));

        foreach (['addresses', 'user_entitlements'] as $table) {
            $this->inChunks(DB::table($table)
                ->where('owner_type', $user->getMorphClass())
                ->where('owner_id', $user->getKey()));
        }

        // Where the sessions are kept in the database, the other browsers of a deleted member are signed out at once and do not leave
        // their address and browser behind until the session runs out.
        if (config('session.driver') === 'database') {
            $this->inChunks(DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->getKey()));
        }

        $this->forgetDiscordRows($user);
    }

    /**
     * What accounts deleted BEFORE this class existed left behind: the same tables, found by the account they name being gone. It is for
     * `metaforger:members:forget-leftovers`, run once after upgrading. The files cannot be found from here: the rows that named them
     * went with the account (a data export's zip is swept by PruneDataExports; see docs/account-deletion.md for the rest).
     *
     * @return array<string, int> the rows deleted in each table, or, with `$delete` false, the rows that would be
     */
    public function ofMembersWhoAreGone(bool $delete = true): array
    {
        $morph = (new User)->getMorphClass();
        $counts = [];

        $keys = fn (): QueryBuilder => $this->nobodyOwns(DB::table('personal_access_tokens')->where('tokenable_type', $morph), 'personal_access_tokens', 'tokenable_id');

        // The log first, then the keys it hangs from.
        $counts['token_logs'] = $this->take(DB::table('token_logs')->whereIn('personal_access_token_id', $keys()->select('id')), $delete);
        $counts['personal_access_tokens'] = $this->take($keys(), $delete);
        $counts['notifications'] = $this->take($this->nobodyOwns(DB::table('notifications')->where('notifiable_type', $morph), 'notifications', 'notifiable_id'), $delete);

        foreach (['addresses', 'user_entitlements'] as $table) {
            $counts[$table] = $this->take($this->nobodyOwns(DB::table($table)->where('owner_type', $morph), $table, 'owner_id'), $delete);
        }

        if (config('session.driver') === 'database') {
            $sessions = (string) config('session.table', 'sessions');
            $counts[$sessions] = $this->take($this->nobodyOwns(DB::table($sessions), $sessions, 'user_id'), $delete);
        }

        foreach ($this->discordTables() as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = $this->take($this->nobodyOwns(DB::table($table), $table, 'user_id'), $delete);
            }
        }

        return $counts;
    }

    /**
     * Only the rows that name a member (not a guest's session, whose user is null) who is not in `users`.
     */
    private function nobodyOwns(QueryBuilder $query, string $table, string $column): QueryBuilder
    {
        return $query
            ->whereNotNull("$table.$column")
            ->whereNotExists(fn (QueryBuilder $users) => $users->from('users')->selectRaw('1')->whereColumn('users.id', "$table.$column"));
    }

    /**
     * Count the rows of a query, or delete them a chunk at a time by their ids (a delete with a limit and a subquery on the same table is
     * written differently by each database; ids are the same on all).
     */
    private function take(QueryBuilder $query, bool $delete): int
    {
        if (! $delete) {
            return $query->count();
        }

        $total = 0;

        do {
            $ids = (clone $query)->limit(1000)->pluck('id')->all();
            $deleted = $ids === [] ? 0 : DB::table((string) $query->from)->whereIn('id', $ids)->delete();
            $total += $deleted;
        } while ($deleted > 0);

        return $total;
    }

    /**
     * The keys and every row of their log. The log is deleted by hand, in chunks, before the keys, and not left to the foreign key's
     * cascade: a key with a long history is one statement that holds the table for as long as it takes.
     */
    private function forgetApiKeys(User $user): void
    {
        $keys = DB::table('personal_access_tokens')
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey());

        $ids = (clone $keys)->pluck('id')->all();

        if ($ids === []) {
            return;
        }

        $this->inChunks(TokenLog::query()->whereIn('personal_access_token_id', $ids));

        $keys->delete();
    }

    /**
     * The Discord package keeps a member's sync state, notification choices and failed syncs under their id, with no foreign key.
     * Guarded, because the tables are the package's and a site may not have them.
     */
    private function forgetDiscordRows(User $user): void
    {
        foreach ($this->discordTables() as $table) {
            if (Schema::hasTable($table)) {
                $this->inChunks(DB::table($table)->where('user_id', $user->getKey()));
            }
        }
    }

    /**
     * @return list<string>
     */
    private function discordTables(): array
    {
        return array_map(fn (string $name) => 'discord_'.$name, self::DISCORD_TABLE_NAMES);
    }

    /**
     * @param  EloquentBuilder<TokenLog>|QueryBuilder  $query
     */
    private function inChunks(EloquentBuilder|QueryBuilder $query): void
    {
        do {
            $deleted = (clone $query)->limit(self::CHUNK)->delete();
        } while ($deleted > 0);
    }
}
