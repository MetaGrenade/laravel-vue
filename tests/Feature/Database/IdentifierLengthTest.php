<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * MySQL refuses an index name longer than 64 characters, and Laravel makes one from the table and every column
 * ("table_column_column_unique"), so a migration with a long table name and a two-column index fails there and nowhere else:
 * SQLite does not mind. This fails the build on any database, so it is found here and not on someone's MySQL.
 */
class IdentifierLengthTest extends TestCase
{
    use RefreshDatabase;

    /** MySQL's limit on the name of an index. */
    private const LONGEST = 64;

    #[Test]
    public function no_index_name_is_longer_than_mysql_allows(): void
    {
        $tooLong = [];

        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            foreach (Schema::getIndexes($table) as $index) {
                if (strlen($index['name']) > self::LONGEST) {
                    $tooLong[] = "{$table}.{$index['name']} (".strlen($index['name']).')';
                }
            }
        }

        $this->assertSame([], $tooLong, 'Name these indexes by hand: $table->unique([...], \'short_name\').');
    }
}
