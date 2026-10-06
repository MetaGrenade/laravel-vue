<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * On PostgreSQL, Laravel's enum() column is a varchar with a CHECK constraint.
 * The earlier migration that introduced the 'scheduled' blog status
 * (2025_05_06_010000_update_blog_status_enum_for_scheduling) changed the MySQL
 * enum and rebuilt the SQLite column but never touched that constraint, so
 * PostgreSQL databases still reject 'scheduled'.
 *
 * That migration has already been recorded as run on existing installations,
 * so the fix has to be a new migration. It is safe on fresh databases too: it
 * replaces whatever status CHECK constraint exists with the four-value one,
 * and does nothing on other database engines.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        $this->replaceStatusConstraint(['draft', 'scheduled', 'published', 'archived']);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        // Posts that were waiting to be published go back to drafts first,
        // otherwise the narrower constraint cannot be added.
        DB::table('blogs')->where('status', 'scheduled')->update(['status' => 'draft']);

        $this->replaceStatusConstraint(['draft', 'published', 'archived']);
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }

    /**
     * Drop every CHECK constraint on blogs.status, whatever it is called, and
     * add one that allows exactly the given values.
     *
     * @param  list<string>  $allowed
     */
    private function replaceStatusConstraint(array $allowed): void
    {
        $existing = DB::select(
            "select conname from pg_constraint
             where conrelid = 'blogs'::regclass
               and contype = 'c'
               and pg_get_constraintdef(oid) ilike '%status%'",
        );

        foreach ($existing as $constraint) {
            DB::statement('ALTER TABLE blogs DROP CONSTRAINT "'.str_replace('"', '""', $constraint->conname).'"');
        }

        $values = implode(',', array_map(fn (string $value) => "'".str_replace("'", "''", $value)."'", $allowed));

        DB::statement("ALTER TABLE blogs ADD CONSTRAINT blogs_status_check CHECK (status IN ({$values}))");
    }
};
