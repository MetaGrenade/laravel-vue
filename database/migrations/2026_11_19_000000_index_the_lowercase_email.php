<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An index on `LOWER(email)`, for SQLite and PostgreSQL.
 *
 * An address typed in other letters than the account holds is found by asking `LOWER(email) = LOWER(?)` (App\Support\Members\EmailLookup),
 * the question MySQL and MariaDB answer on their own because they compare text without regard to case. SQLite and PostgreSQL compare
 * exactly, so that question cannot use the unique index on `email`, and without this one it reads every member to answer it. It is asked
 * whenever the address typed on the sign-in page, the API sign-in or the password reset form is not one that exists (every wrong
 * address, and every bot), so it is the question that is asked most by people who have no account to be rate limited by.
 *
 * MySQL and MariaDB do not get one (they are not asked: the exact match already ignores case there, and neither can index an
 * expression the same way), and `LOWER(nickname)` is not indexed: it is asked only when a name is chosen or changed.
 *
 * The index is named for what it holds, and `down()` takes back that name.
 */
return new class extends Migration
{
    public const INDEX = 'users_lower_email_index';

    public function up(): void
    {
        if (! $this->wanted()) {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS '.self::INDEX.' ON users (LOWER(email))');
    }

    public function down(): void
    {
        if (! $this->wanted()) {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
    }

    private function wanted(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['sqlite', 'pgsql'], true);
    }
};
