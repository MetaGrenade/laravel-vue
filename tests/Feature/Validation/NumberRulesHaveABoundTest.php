<?php

namespace Tests\Feature\Validation;

use Illuminate\Support\Facades\File;
use Tests\Support\RuleScanner;
use Tests\TestCase;

/**
 * Every rule that takes a number and saves it has to say how big it can be (docs/upgrading.md, "Numbers that were bigger than their column").
 *
 * A rule such as `['required', 'integer']` takes any number up to 9,223,372,036,854,775,807. SQLite saves it in any integer column, so the
 * tests pass; MySQL, MariaDB and PostgreSQL refuse what the column cannot hold ("Out of range value") and the person gets a 500 page. This
 * reads the validation rules in `app/Http` and names the ones that give no upper bound.
 *
 * Not named: a rule with `max:`, `between:`, a closed set or a number of digits; an `exists:` (the number is an id that is looked up, and
 * an id column is 64 bits, which is what `integer` keeps it inside); and a field named as an id (`user_id`, `ids.*`, `reports.*.id`), which
 * is the same. The list below is for the rest, each with its reason. A rule that is not on it and has no bound fails here, and a line on it
 * that no longer matches a rule fails too, so the list cannot go stale.
 */
class NumberRulesHaveABoundTest extends TestCase
{
    /**
     * `path::field` => why it takes any number.
     *
     * @var array<string, string>
     */
    private const NOT_SAVED = [
        'Controllers/ForumPostController.php::page' => 'a page number: it becomes an offset and is not saved (9223372036854775807 gives an empty page)',
        'Controllers/ForumThreadActionController.php::page' => 'a page number: it becomes an offset and is not saved (9223372036854775807 gives an empty page)',
        'Controllers/Admin/BlogCommentController.php::reports.*' => 'ids of rows, looked up in a 64 bit id column',
        'Controllers/Admin/ForumReviewController.php::replies.*' => 'ids of rows, looked up in a 64 bit id column',
        'Controllers/Admin/ForumReviewController.php::threads.*' => 'ids of rows, looked up in a 64 bit id column',
        'Controllers/Ecommerce/ProductCatalogController.php::category.*' => 'ids of rows that filter a list, looked up in a 64 bit id column',
        'Controllers/Ecommerce/ProductCatalogController.php::tags.*' => 'ids of rows that filter a list, looked up in a 64 bit id column',
        'Controllers/Ecommerce/TebexStorefrontController.php::category' => 'the id of a Tebex category: it picks a row of a list and is not saved',
    ];

    public function test_every_rule_that_takes_a_number_and_saves_it_says_how_big_it_can_be(): void
    {
        $unbounded = $this->unbounded();
        $missing = array_values(array_diff(array_keys($unbounded), array_keys(self::NOT_SAVED)));

        $this->assertSame(
            [],
            $missing,
            "These rules take a number as big as PHP holds. Give each a max: (2147483647 for an int column, see NumberRangeTest), or, if the value is never saved in a column, add it to NOT_SAVED with the reason:\n".implode("\n", array_map(fn (string $key) => "  {$key}  ({$unbounded[$key]})", $missing)),
        );
    }

    public function test_every_line_of_the_list_still_names_a_rule_without_a_bound(): void
    {
        $stale = array_values(array_diff(array_keys(self::NOT_SAVED), array_keys($this->unbounded())));

        $this->assertSame([], $stale, "These are on the list but now say how big they can be (or are gone): take them off.\n".implode("\n", $stale));
    }

    public function test_the_scan_sees_the_rules_it_is_meant_to(): void
    {
        // A scan that reads nothing passes for ever. These are written the two ways rules are written (an array, and a string with pipes).
        $all = RuleScanner::entries();

        $this->assertArrayHasKey('Requests/Admin/StoreFaqRequest.php::order', $all);
        $this->assertArrayHasKey('Requests/Admin/FaqCategoryRequest.php::order', $all);
        $this->assertStringContainsString('between:-2147483648,2147483647', $all['Requests/Admin/StoreFaqRequest.php::order'][0]);
        $this->assertStringContainsString('max:2147483647', $all['Requests/Admin/StoreTokenRequest.php::hourly_quota'][0]);
        $this->assertGreaterThan(300, count($all), 'far fewer rules were found than the application has');
    }

    public function test_the_scan_names_what_it_should_in_a_file_it_is_given(): void
    {
        $directory = storage_path('framework/testing/number-rules-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($directory);

        try {
            File::put($directory.'/Sample.php', <<<'PHP'
<?php

class Sample
{
    public function store() { return ['order' => 'integer|between:1,5', 'amount' => ['required', 'numeric', 'max:100']]; }

    // The same field in the next action says nothing about size: it has to be found, whatever the first one says.
    public function update() { return ['order' => 'integer', 'amount' => ['required', 'numeric', 'max:100']]; }

    // Ids are 64 bit (named as one, or looked up); a closed set and a number of digits are bounded; a quota is none of those.
    public function others() { return ['product_id' => ['integer'], 'ids.*' => ['integer'], 'reports.*.id' => ['integer'], 'owner' => ['integer', 'exists:users,id'], 'kind' => ['integer', 'in:1,2'], 'quota' => ['nullable', 'integer', 'min:1']]; }

    // A list of props with an `integer` key is not a rule, and a price that is a decimal has a size only when it says so.
    public function props() { return ['user' => ['integer' => 1], 'price' => ['required', 'decimal:0,2']]; }
}
PHP);

            $this->assertEqualsCanonicalizing(
                ['Sample.php::order', 'Sample.php::quota', 'Sample.php::price'],
                array_keys($this->unbounded($directory)),
            );
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * `path::field` => the rule text, for every rule that takes a number and gives no upper bound.
     *
     * @return array<string, string>
     */
    private function unbounded(?string $base = null): array
    {
        $found = [];

        foreach (RuleScanner::entries($base) as $key => $rules) {
            $field = substr($key, strpos($key, '::') + 2);

            // An id: 64 bits, as the column it is looked up in (`user_id`, `ids.*`, `reports.*.id`).
            if (preg_match('/(^|[._])ids?(\.\*)?$/', $field) === 1) {
                continue;
            }

            foreach ($rules as $rule) {
                // `integer`, `numeric` or `decimal` as a rule (`'integer'`, `|numeric|`, `'decimal:0,2'`), and not as the key of a list of props.
                $takesNumber = preg_match('/[\'|](?:integer|numeric|decimal)(?:[\'|:](?!\s*=>))/', $rule) === 1;
                $bounded = preg_match('/max:|between:|size:|digits|[\'|]in:|Rule::in|Rule::enum|Enum|exists/', $rule) === 1;

                if ($takesNumber && ! $bounded) {
                    $found[$key] ??= preg_replace('/\s+/', ' ', trim($rule));
                }
            }
        }

        return $found;
    }
}
