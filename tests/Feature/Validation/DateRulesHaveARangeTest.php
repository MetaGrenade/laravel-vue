<?php

namespace Tests\Feature\Validation;

use Illuminate\Support\Facades\File;
use Tests\Support\RuleScanner;
use Tests\TestCase;

/**
 * Every rule that takes a date and saves it has to say how far off it can be (docs/upgrading.md, "Dates the database cannot keep").
 *
 * `date` accepts the year 1 and the year 100000. SQLite keeps either, so the tests pass; MySQL and MariaDB keep only 1970 to 2038 in a
 * `timestamp` column and refuse the rest, and the person gets a 500 page. This reads the validation rules in `app/Http` and names the ones
 * that take a date with neither `StorableDate` (for a `timestamp` column) nor a `before:` (for a `datetime` column).
 *
 * The list below is for the rest, each with its reason. A rule that is not on it and has no end fails here, and a line on it that no longer
 * matches a rule fails too, so the list cannot go stale.
 */
class DateRulesHaveARangeTest extends TestCase
{
    /**
     * `path::field` => why it takes any date.
     *
     * @var array<string, string>
     */
    private const NOT_SAVED = [
        'Controllers/Admin/BillingController.php::date_from' => 'a list filter: brought into range by TimestampRange::nearest() before the query',
        'Controllers/Admin/BillingController.php::date_to' => 'a list filter: brought into range by TimestampRange::nearest() before the query',
        'Controllers/Admin/SearchAnalyticsController.php::date_from' => 'a list filter: brought into range by TimestampRange::nearest() before the query',
        'Controllers/Admin/SearchAnalyticsController.php::date_to' => 'a list filter: brought into range by TimestampRange::nearest() before the query',
    ];

    public function test_every_rule_that_takes_a_date_and_saves_it_says_how_far_off_it_can_be(): void
    {
        $open = $this->openEnded();
        $missing = array_values(array_diff(array_keys($open), array_keys(self::NOT_SAVED)));

        $this->assertSame(
            [],
            $missing,
            "These rules take any date. Give each `new StorableDate` (a timestamp column) or a before: (a datetime column), or, if the value is never saved in a column, add it to NOT_SAVED with the reason:\n".implode("\n", array_map(fn (string $key) => "  {$key}  ({$open[$key]})", $missing)),
        );
    }

    public function test_every_line_of_the_list_still_names_a_rule_without_an_end(): void
    {
        $stale = array_values(array_diff(array_keys(self::NOT_SAVED), array_keys($this->openEnded())));

        $this->assertSame([], $stale, "These are on the list but now say how far off they can be (or are gone): take them off.\n".implode("\n", $stale));
    }

    public function test_the_scan_sees_the_rules_it_is_meant_to(): void
    {
        // A scan that reads nothing passes for ever. These are the application's own date rules, which it has to find.
        $all = RuleScanner::entries();

        foreach ([
            'Requests/Admin/BlogRequest.php::scheduled_for',
            'Requests/Admin/CouponRequest.php::starts_at',
            'Requests/Admin/CouponRequest.php::ends_at',
            'Requests/Admin/PollRequest.php::starts_at',
            'Requests/Admin/PollRequest.php::ends_at',
            'Requests/Admin/StoreTokenRequest.php::expires_at',
            'Requests/Admin/UpdateTokenRequest.php::expires_at',
            'Requests/Admin/UpdateDataErasureRequest.php::processed_at',
            'Requests/Admin/UpdateDataExportRequest.php::completed_at',
            'Controllers/Admin/GiftCardController.php::expires_at',
        ] as $key) {
            $this->assertArrayHasKey($key, $all);
            $this->assertStringContainsString('StorableDate', implode(' ', $all[$key]), $key);
        }

        // Both of the gift card's actions.
        $this->assertCount(2, $all['Controllers/Admin/GiftCardController.php::expires_at']);
        $this->assertStringContainsString('before:9999-12-31', $all['Controllers/Admin/UsersController.php::until'][0]);
        $this->assertStringContainsString('before:9999-12-31', $all['Controllers/Admin/UserModerationController.php::until'][0]);
    }

    public function test_the_scan_names_what_it_should_in_a_file_it_is_given(): void
    {
        $directory = storage_path('framework/testing/date-rules-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($directory);

        try {
            File::put($directory.'/Sample.php', <<<'PHP'
<?php

class Sample
{
    public function store() { return ['listed' => ['nullable', 'date'], 'piped' => 'nullable|date|after:now', 'first' => 'date|required', 'shaped' => ['date_format:Y-m-d'], 'built' => [Rule::date()]]; }

    // The same field in the next action says nothing about how far off: it has to be found, whatever the first one says.
    public function update() { return ['twice' => ['date', new StorableDate]]; }

    public function other() { return ['twice' => ['date']]; }

    // These say how far off, one way or the other, and a lower bound alone does not.
    public function bounded() { return ['kept' => ['date', new StorableDate], 'ends' => ['date_format:Y-m-d', 'before:9999-12-31'], 'upto' => 'date|before_or_equal:today', 'since' => ['date', 'after:2000-01-01', 'after_or_equal:today']]; }

    // None of these is a date: a name that holds the word, a message, and a list of props with a `date` key.
    public function others() { return ['update' => ['string'], 'format' => 'string|max:10', 'props' => ['date' => 1], 'label.date' => 'Pick one.']; }
}
PHP);

            $this->assertEqualsCanonicalizing(
                ['Sample.php::listed', 'Sample.php::piped', 'Sample.php::first', 'Sample.php::shaped', 'Sample.php::built', 'Sample.php::twice', 'Sample.php::since'],
                array_keys($this->openEnded($directory)),
            );
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * `path::field` => the rule text, for every rule that takes a date and gives no end.
     *
     * @return array<string, string>
     */
    private function openEnded(?string $base = null): array
    {
        $found = [];

        foreach (RuleScanner::entries($base) as $key => $rules) {
            foreach ($rules as $rule) {
                // `date` or `date_format:` as a rule (`'date'`, `|date|`), and not as the key of a list of props.
                $takesDate = preg_match('/[\'|](?:date|date_format:[^\'|]*)(?:[\'|](?!\s*=>))|Rule::date\(/', $rule) === 1;
                $ends = preg_match('/StorableDate|[\'|]before(?:_or_equal)?:/', $rule) === 1;

                if ($takesDate && ! $ends) {
                    $found[$key] ??= preg_replace('/\s+/', ' ', trim($rule));
                }
            }
        }

        return $found;
    }
}
