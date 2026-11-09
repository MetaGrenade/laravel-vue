<?php

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The browsers that are signed in to an account, as far as the site can tell, and ending one (docs/two-factor.md, "Browser sessions").
 *
 * Only the `database` session driver keeps a row for each session that says whose it is. Redis, files and cookies keep the session
 * under its id and nothing else, so the site cannot list the sessions of one account or end one by id. For those drivers the panel does
 * not list anything, and "sign out the other browsers" works another way: it changes what the account's password hash is, which every
 * session is checked against (`AuthenticateSession`), so it needs no list.
 */
class BrowserSessions
{
    /**
     * Whether this site can say which sessions an account has (and end one of them by its id).
     */
    public static function canList(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * The table the sessions are in, as `SESSION_CONNECTION` and `SESSION_TABLE` say.
     */
    public static function table(): Builder
    {
        $connection = config('session.connection');

        return DB::connection(is_string($connection) ? $connection : null)->table((string) config('session.table', 'sessions'));
    }

    /**
     * End one of the account's sessions. It does nothing for a driver that cannot name them.
     */
    public static function forget(User $user, string $sessionId): void
    {
        if (! self::canList()) {
            return;
        }

        self::table()->where('id', $sessionId)->where('user_id', $user->getKey())->delete();
    }

    /**
     * Delete the rows of every other session of the account, so the list is true at once and not when each browser next asks for a page.
     * The password hash is what signs them out; this only tidies the list.
     */
    public static function forgetOthers(User $user, string $currentSessionId): void
    {
        if (! self::canList()) {
            return;
        }

        self::table()->where('user_id', $user->getKey())->where('id', '!=', $currentSessionId)->delete();
    }
}
