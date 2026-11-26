<?php

namespace Tests\Feature\Validation;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\SupportTicket;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RuleScanner;
use Tests\TestCase;

/**
 * A rule that looks a number up (`exists:users,id`) has to say `integer` first. Without it the text that was sent goes to the
 * database, and PostgreSQL refuses to compare a number column with text or with a number too big for it: a 500 page, where the form
 * should say that the choice is not valid. Laravel skips `exists` for a field that has already failed a rule, so the order is what
 * keeps it from the database. SQLite, the database this suite usually runs on, only finds nothing, which is why the rules are read
 * here, and why the requests below look at what the database was asked.
 */
class IdRulesAreIntegersTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_rule_that_looks_up_an_id_says_it_is_a_whole_number_first(): void
    {
        $unchecked = $this->unchecked();

        $this->assertSame(
            [],
            array_keys($unchecked),
            "These rules send what was typed to a number column. Put 'integer' before the exists:\n".implode("\n", array_map(fn (string $key, string $rule) => "  {$key}  ({$rule})", array_keys($unchecked), $unchecked)),
        );
    }

    public function test_the_scan_names_what_it_should_in_a_file_it_is_given(): void
    {
        $directory = storage_path('framework/testing/id-rules-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($directory);

        try {
            File::put($directory.'/Sample.php', <<<'PHP'
<?php

class Sample
{
    public function good() { return ['a_id' => ['required', 'integer', 'exists:users,id'], 'b_id' => 'nullable|integer|exists:users,id', 'c_id' => ['integer', Rule::exists('users', 'id')->where('x', 1)], 'name' => ['exists:roles,name']]; }

    public function bad() { return ['d_id' => ['required', 'exists:users,id'], 'e_id' => 'nullable|exists:users,id', 'f_id' => [Rule::exists('users', 'id')], 'g_id' => ['exists:users,id', 'integer']]; }

    // The same field in the next action leaves it out: it has to be found, whatever the first one says.
    public function again() { return ['a_id' => ['exists:users,id']]; }

    // Looked up in another column of numbers.
    public function other() { return ['h_id' => ['exists:poll_options,poll_id']]; }
}
PHP);

            $this->assertEqualsCanonicalizing(
                ['Sample.php::a_id', 'Sample.php::d_id', 'Sample.php::e_id', 'Sample.php::f_id', 'Sample.php::g_id', 'Sample.php::h_id'],
                array_keys($this->unchecked($directory)),
            );
        } finally {
            File::deleteDirectory($directory);
        }

        $this->assertGreaterThan(30, count(array_filter(RuleScanner::entries(), fn (array $rules) => str_contains(implode(' ', $rules), 'exists'))), 'far fewer rules were found than the application has');
    }

    /**
     * Every form field that a request to the database was seen to reach with text, on PostgreSQL.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function fields(): array
    {
        return [
            'the plan of a setup intent' => ['post', '/pricing/setup-intent', 'plan_id'],
            'the plan a code is tried on, from the pricing page' => ['post', '/pricing/promotion-code', 'plan_id'],
            'the plan subscribed to from the pricing page' => ['post', '/pricing/subscribe', 'plan_id'],
            'the plan a code is tried on, from the settings' => ['post', '/settings/billing/promotion-code', 'plan_id'],
            'the plan subscribed to from the settings' => ['post', '/settings/billing/subscribe', 'plan_id'],
            'the category of a new board' => ['post', '/acp/forums/boards', 'forum_category_id'],
            'the category of a board' => ['put', '/acp/forums/boards/{board}', 'forum_category_id'],
            'the customer of a new ticket' => ['post', '/acp/support/tickets', 'user_id'],
            'the customer of a ticket' => ['put', '/acp/support/tickets/{ticket}', 'user_id'],
            'the agent of a ticket' => ['put', '/acp/support/tickets/{ticket}', 'assigned_to'],
            'the agent a ticket is assigned to' => ['put', '/acp/support/tickets/{ticket}/assign', 'assigned_to'],
        ];
    }

    #[Test]
    #[DataProvider('fields')]
    public function something_that_cannot_be_a_number_is_refused_by_the_form_and_not_looked_up(string $method, string $address, string $field): void
    {
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $this->actingAs($admin);

        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'position' => 1]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'News', 'slug' => 'news', 'position' => 1]);
        $ticket = SupportTicket::factory()->create();
        $address = str_replace(['{board}', '{ticket}'], [(string) $board->id, (string) $ticket->id], $address);

        $asked = [];
        DB::listen(function (QueryExecuted $query) use (&$asked): void {
            array_push($asked, ...array_values($query->bindings));
        });

        foreach (['abc', '1.5', '9223372036854775808'] as $value) {
            $this->{$method.'Json'}($address, [$field => $value])->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertNotContains($value, $asked, "{$value} was sent to the database");
        }
    }

    /**
     * `path::field` => the rule text, for every rule that looks a value up in a column of ids without saying `integer` before.
     *
     * @return array<string, string>
     */
    private function unchecked(?string $base = null): array
    {
        $found = [];

        foreach (RuleScanner::entries($base) as $key => $rules) {
            foreach ($rules as $rule) {
                // `exists:users,id` or `Rule::exists('users', 'id')`, and the same for a column that ends in `_id`.
                if (preg_match('/exists:\w+,(?:\w+_)?id\b|Rule::exists\([^)]*,\s*\'(?:\w+_)?id\'/', $rule, $match, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }

                if (preg_match('/[\'|]integer[\'|]/', substr($rule, 0, $match[0][1])) !== 1) {
                    $found[$key] ??= preg_replace('/\s+/', ' ', trim($rule));
                }
            }
        }

        return $found;
    }
}
