<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * `unique:table,column`, with `Ada` and `ada` counted as the same value on every database.
 *
 * MySQL and MariaDB compare text without regard to case (their usual collations are case-insensitive), so the plain `unique` rule already
 * refuses `ada` when `Ada` is taken. SQLite and PostgreSQL compare exactly, so on them the same rule lets both through: two members with
 * names that differ only by case (one can pass for the other), or two accounts for one mailbox. This asks the database to fold the case of
 * both sides, so every database gives the answer MySQL and MariaDB give.
 *
 * It reads the whole column (a `LOWER()` of it cannot use the index), so it belongs on the few places a name or an address is chosen or
 * changed, and not on a list or a search. SQLite folds the 26 ASCII letters and no others (its own limit); PostgreSQL, MySQL and MariaDB
 * fold every letter their collation knows.
 *
 * A value the row being changed already holds is never refused, whatever other rows hold: a site that grew case variants before this rule
 * must still be able to save the form of a member who has one, with the name unchanged.
 */
final class UniqueIgnoringCase implements ValidationRule
{
    private function __construct(
        private readonly string $table,
        private readonly string $column,
        private readonly int|string|null $ignore = null,
    ) {}

    public static function in(string $table, string $column): self
    {
        return new self($table, $column);
    }

    /**
     * The row that is being changed, which does not count (its `id`).
     */
    public function ignore(int|string|null $id): self
    {
        return new self($this->table, $this->column, $id);
    }

    /**
     * Whether a row other than the ignored one holds the value, with case set aside. For code that makes up a name and must find a free one.
     */
    public static function taken(string $table, string $column, string $value, int|string|null $ignore = null): bool
    {
        $wrapped = DB::query()->getGrammar()->wrap($column);

        return DB::table($table)
            ->whereRaw("LOWER({$wrapped}) = LOWER(?)", [$value])
            ->when($ignore !== null, fn ($query) => $query->where('id', '!=', $ignore))
            ->exists();
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        // Whether it is a string at all is for the other rules to say.
        if (! is_string($value) || $value === '') {
            return;
        }

        if ($this->ignore !== null && DB::table($this->table)->where('id', $this->ignore)->value($this->column) === $value) {
            return;
        }

        if (self::taken($this->table, $this->column, $value, $this->ignore)) {
            $fail('validation.unique')->translate();
        }
    }
}
