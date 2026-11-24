<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The index behind the bell in the header (docs/indexes.md).
 *
 * Every page a signed-in member opens counts their unread notifications and takes the newest ten of them. The index holds the member,
 * `read_at` and `created_at`, in that order, so that both are one stretch of it: the columns and their order are written out here, and
 * not read from the migration, so changing the migration alone fails (the same columns the other way round answer another question).
 * DDL commits the test's transaction on MySQL, so each test that changes the schema puts it back.
 */
class UnreadNotificationsIndexTest extends TestCase
{
    use RefreshDatabase;

    private const FILE = '2026_11_23_000000_index_the_unread_notifications.php';

    private const INDEX = 'notifications_unread_by_time';

    /** The index Laravel makes for the two columns of `morphs()`, a prefix of ours and not ours to take away. */
    private const LARAVELS = 'notifications_notifiable_type_notifiable_id_index';

    private const COLUMNS = ['notifiable_type', 'notifiable_id', 'read_at', 'created_at'];

    #[Test]
    public function a_members_unread_notifications_are_one_stretch_of_an_index_in_the_order_the_bell_shows_them(): void
    {
        $this->assertSame(self::INDEX, $this->nameOfIndexWithColumns(self::COLUMNS));
    }

    #[Test]
    public function the_two_questions_the_header_asks_are_answered_by_it_and_nothing_is_sorted(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The plan is read on SQLite, where a table this small is still searched by its index. MySQL and PostgreSQL would read it whole, and say so in a plan that depends on how many rows there are.');
        }

        $member = User::factory()->create();

        foreach (range(1, 12) as $n) {
            DB::table('notifications')->insert([
                'id' => (string) Str::uuid(),
                'type' => 'App\\Notifications\\Example',
                'notifiable_type' => $member->getMorphClass(),
                'notifiable_id' => $member->id,
                'data' => json_encode(['title' => 'Example '.$n]),
                'read_at' => $n <= 3 ? now() : null,
                'created_at' => now()->subMinutes($n),
                'updated_at' => now()->subMinutes($n),
            ]);
        }

        // The very queries the header asks, as it asks them.
        $asked = [];
        DB::listen(function ($query) use (&$asked) {
            if (str_contains($query->sql, 'from "notifications"') && str_contains($query->sql, 'read_at')) {
                $asked[] = ['sql' => $query->sql, 'bindings' => $query->bindings];
            }
        });

        $this->actingAs($member)->get(route('home'))->assertOk();

        $this->assertCount(2, $asked, 'the header no longer asks the two questions this index answers: '.json_encode(array_column($asked, 'sql')));

        foreach ($asked as $query) {
            $plan = collect(DB::select('explain query plan '.$query['sql'], $query['bindings']))
                ->map(fn ($row) => $row->detail)
                ->implode(' | ');

            $this->assertStringContainsString(self::INDEX, $plan, 'the question reads more than the index: '.$plan);
            $this->assertStringNotContainsString('TEMP B-TREE', $plan, 'the question sorts the rows it reads: '.$plan);
        }
    }

    #[Test]
    public function down_takes_back_what_up_made_and_nothing_else(): void
    {
        $before = $this->indexNames();

        $this->migration()->down();

        try {
            $this->assertNull($this->nameOfIndexWithColumns(self::COLUMNS));
            $this->assertSame(array_values(array_diff($before, [self::INDEX])), $this->indexNames(), 'down() took back an index it did not make');
            $this->assertContains(self::LARAVELS, $this->indexNames(), 'the index Laravel made for the member is not this migration\'s');
        } finally {
            $this->migration()->up();
        }

        $this->assertSame($before, $this->indexNames(), 'up() makes the same ones again');
    }

    #[Test]
    public function running_it_again_makes_nothing_new(): void
    {
        $before = $this->indexNames();

        $this->migration()->up();

        $this->assertSame($before, $this->indexNames());
    }

    #[Test]
    public function down_copes_with_an_index_somebody_already_took_away(): void
    {
        Schema::table('notifications', fn ($table) => $table->dropIndex(self::INDEX));

        try {
            $this->migration()->down();

            $this->assertNull($this->nameOfIndexWithColumns(self::COLUMNS));
        } finally {
            $this->migration()->up();
        }

        $this->assertNotNull($this->nameOfIndexWithColumns(self::COLUMNS));
    }

    #[Test]
    public function an_index_a_person_made_is_neither_doubled_nor_dropped(): void
    {
        $this->migration()->down();

        // Made before this migration ran, for instance to build it without a lock on a large database: it starts with the same four
        // columns and goes on, under a name of their own.
        $theirs = 'notifications_by_member_unread';

        Schema::table('notifications', fn ($table) => $table->index([...self::COLUMNS, 'type'], $theirs));

        try {
            $this->migration()->up();

            $this->assertSame([$theirs], $this->namesOfIndexesStarting(self::COLUMNS), 'not doubled');
            $this->assertNotContains(self::INDEX, $this->indexNames());

            $this->migration()->down();

            $this->assertContains($theirs, $this->indexNames(), 'down() took back an index it did not make');
        } finally {
            Schema::table('notifications', fn ($table) => $table->dropIndex($theirs));
            $this->migration()->up();
        }

        $this->assertSame(self::INDEX, $this->nameOfIndexWithColumns(self::COLUMNS));
    }

    #[Test]
    public function an_index_with_only_the_first_columns_is_not_enough(): void
    {
        $this->migration()->down();

        // What a site that had added `read_at` to the index itself has: it answers the count and still sorts the newest ten.
        $theirs = 'notifications_by_member_and_read';

        Schema::table('notifications', fn ($table) => $table->index(['notifiable_type', 'notifiable_id', 'read_at'], $theirs));

        try {
            $this->migration()->up();

            $this->assertSame(self::INDEX, $this->nameOfIndexWithColumns(self::COLUMNS));
        } finally {
            Schema::table('notifications', fn ($table) => $table->dropIndex($theirs));
        }
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::FILE);
    }

    /**
     * @return list<string>
     */
    private function indexNames(): array
    {
        $names = array_map(fn (array $index) => $index['name'], Schema::getIndexes('notifications'));

        sort($names);

        return $names;
    }

    /**
     * @param  list<string>  $columns
     */
    private function nameOfIndexWithColumns(array $columns): ?string
    {
        foreach (Schema::getIndexes('notifications') as $index) {
            if ($index['columns'] === $columns) {
                return $index['name'];
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $columns
     * @return list<string>
     */
    private function namesOfIndexesStarting(array $columns): array
    {
        $names = [];

        foreach (Schema::getIndexes('notifications') as $index) {
            if (array_slice($index['columns'], 0, count($columns)) === $columns) {
                $names[] = $index['name'];
            }
        }

        sort($names);

        return $names;
    }
}
