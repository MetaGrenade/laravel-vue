<?php

namespace App\Support\Members;

use App\Models\User;
use App\Rules\UniqueIgnoringCase;
use Illuminate\Support\Facades\DB;

/**
 * The account an email address names, whatever the case it is written in.
 *
 * MySQL and MariaDB compare text without regard to case (their usual collations), so there `Ada@Example.com` already finds
 * `ada@example.com`. SQLite and PostgreSQL compare exactly and found nothing: a phone that capitalises the first letter of what is
 * typed, or an address an administrator wrote in other letters, left the person unable to sign in or to ask for a reset. This gives
 * every database the answer MySQL and MariaDB give.
 *
 * The address as it is written is tried first, so a site that grew two accounts differing only by case (SQLite and PostgreSQL let that
 * happen before names were compared without regard to case, see {@see UniqueIgnoringCase}) keeps signing each of them in by its own
 * spelling. Any other spelling of those two names both and so none: an account is never picked by guessing.
 *
 * MySQL and MariaDB are asked once and no more: what the exact match did not find, the other letters would not either (a site that has
 * given the column a case-sensitive collation is the one that has to look further, and does not get it: it is not the usual setup, and the
 * lookup runs for every address typed, wrong ones too, which then would read the whole table). On SQLite and PostgreSQL the second
 * question is `LOWER(email)`, which `users_lower_email_index` answers without reading the table.
 */
final class EmailLookup
{
    public static function find(string $given): ?User
    {
        if ($given === '') {
            return null;
        }

        $exact = User::query()->where('email', $given)->first();

        if ($exact !== null || self::comparesWithoutCase()) {
            return $exact;
        }

        $matches = User::query()->whereRaw('LOWER(email) = LOWER(?)', [$given])->limit(2)->get();

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * The address the account holds, for a lookup that has to ask the database for it by that spelling (`Auth::attempt()`, a password broker).
     */
    public static function storedAs(string $given): ?string
    {
        return self::find($given)?->email;
    }

    private static function comparesWithoutCase(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
