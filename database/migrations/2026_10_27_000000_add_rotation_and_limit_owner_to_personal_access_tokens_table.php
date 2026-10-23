<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What an API key needs to be rotated, and to let its owner limit it (see docs/api-keys.md).
 *
 * - `rotated_at`: when the key was replaced by a new one. It goes on working until its own end (rotating sets that to the end of the
 *   grace period), so this is how a key that is being replaced is told from one that is not.
 * - `limits_set_by_staff_at`: when staff set the key's hourly or daily limit. Until then the limits are the owner's to change; after it
 *   they are not, so an owner cannot lift a limit staff put on a key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dateTime('rotated_at')->nullable()->after('revoked_at');
            $table->dateTime('limits_set_by_staff_at')->nullable()->after('daily_quota');
        });

        // Until now only staff could set a limit, so every limit that exists was theirs.
        DB::table('personal_access_tokens')
            ->where(fn ($query) => $query->whereNotNull('hourly_quota')->orWhereNotNull('daily_quota'))
            ->update(['limits_set_by_staff_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['rotated_at', 'limits_set_by_staff_at']);
        });
    }
};
