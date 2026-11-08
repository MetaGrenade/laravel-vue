<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sign-in with Google or Discord only needs to know who the person is. The access token and the refresh token the provider hands back
 * were saved next to the profile it sent, and nothing ever read them again, so a backup of the database held live tokens in plain text
 * for no reason. They are no longer kept, and neither is the provider's raw profile (`data`), which nothing read either: the name,
 * nickname, address and picture the site shows are columns of their own.
 *
 * What was saved is emptied first and the columns are dropped after. On MySQL and PostgreSQL dropping a column does not rewrite the rows,
 * so the values can stay in the table's storage until it is rebuilt; writing NULL over them first is what makes them go. A backup made
 * before this migration still has them.
 *
 * `down()` puts the columns back, empty: the tokens that were here cannot be restored, and no sign-in saves them again.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const COLUMNS = ['access_token', 'refresh_token', 'token_expires_at', 'data'];

    public function up(): void
    {
        $present = array_values(array_filter(self::COLUMNS, fn (string $column): bool => Schema::hasColumn('social_accounts', $column)));

        if ($present === []) {
            return;
        }

        DB::table('social_accounts')->update(array_fill_keys($present, null));

        Schema::table('social_accounts', function (Blueprint $table) use ($present) {
            $table->dropColumn($present);
        });
    }

    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('social_accounts', 'access_token')) {
                $table->text('access_token')->nullable();
            }

            if (! Schema::hasColumn('social_accounts', 'refresh_token')) {
                $table->text('refresh_token')->nullable();
            }

            if (! Schema::hasColumn('social_accounts', 'token_expires_at')) {
                $table->timestamp('token_expires_at')->nullable();
            }

            if (! Schema::hasColumn('social_accounts', 'data')) {
                $table->json('data')->nullable();
            }
        });
    }
};
