<?php

namespace Tests\Feature\Validation;

use Illuminate\Support\Facades\File;
use Tests\Support\RuleScanner;
use Tests\TestCase;

/**
 * Every rule that takes text and saves it has to say how long it can be (docs/upgrading.md, "Text that was longer than its column").
 *
 * A rule such as `['required', 'string']` or `'email'` takes a value of any length. SQLite saves it whatever it is, so the tests pass;
 * MySQL, MariaDB and PostgreSQL refuse what does not fit the column ("Data too long") and the person gets a 500 page, and a field with no
 * limit at all lets one request carry megabytes. This reads the validation rules in `app/Http` and names the ones that give no length.
 *
 * The list below is for rules that take text that is never saved, or that are an open question. Each says why. A rule that is not on it
 * and has no `max:` fails here, and a line on it that no longer matches a rule fails too, so the list cannot go stale.
 */
class StringRulesHaveALengthTest extends TestCase
{
    /**
     * `path::field` => why it takes any length.
     *
     * @var array<string, string>
     */
    private const NOT_SAVED = [
        'Controllers/Admin/BillingController.php::status' => 'a filter on a list: compared, never saved',
        'Controllers/Admin/BlogCommentController.php::reason_category' => 'a filter on a list: compared, never saved',
        'Controllers/Auth/TwoFactorChallengeController.php::code' => 'checked against the authenticator, never saved',
        'Controllers/Auth/TwoFactorChallengeController.php::recovery_code' => 'checked against the stored codes, never saved',
        'Controllers/BlogCommentController.php::captcha_token' => 'handed to the captcha provider, never saved',
        'Requests/Api/V1/Blog/StoreBlogCommentRequest.php::captcha_token' => 'handed to the captcha provider, never saved',
        'Controllers/PricingController.php::payment_method' => 'an id handed to Stripe, never saved',
        'Controllers/Settings/SubscriptionController.php::payment_method' => 'an id handed to Stripe, never saved',
        'Requests/Api/V1/StoreTokenRequest.php::password' => 'checked against the hash, never saved',
        'Requests/Auth/LoginRequest.php::password' => 'checked against the hash, never saved',
        'Requests/Api/V1/StoreTokenRequest.php::email' => 'looked up, never saved',
        'Requests/Auth/LoginRequest.php::email' => 'looked up, never saved',
        'Controllers/Auth/NewPasswordController.php::email' => 'looked up, never saved',
        'Controllers/Auth/PasswordResetLinkController.php::email' => 'looked up, never saved',
    ];

    /**
     * An open question, not a decision: the opening post of a thread is saved (`forum_posts.body` is a `longText`, so no column limit is
     * reached), a reply and an edit of any post are limited to 5,000 characters, and the opening post is not. Whoever settles what the
     * opening post may hold takes these two off this list.
     *
     * @var array<string, string>
     */
    private const OPEN = [
        'Controllers/ForumController.php::body' => 'the opening post of a thread (web)',
        'Requests/Api/V1/Forum/StoreForumThreadRequest.php::body' => 'the opening post of a thread (API)',
    ];

    /**
     * Saved, in a column that has no length to reach.
     *
     * @var array<string, string>
     */
    private const LONG_COLUMN = [
        'Requests/Admin/BlogRequest.php::body' => 'a blog post (staff only): `blogs.body` is a longText',
    ];

    public function test_every_rule_that_takes_text_and_saves_it_says_how_long_it_can_be(): void
    {
        $unbounded = $this->unbounded();
        $allowed = array_keys(self::NOT_SAVED + self::OPEN + self::LONG_COLUMN);

        $missing = array_values(array_diff(array_keys($unbounded), $allowed));

        $this->assertSame(
            [],
            $missing,
            "These rules take text of any length. Give each a max: (255 for a varchar(255), 16000 for a text column, see TextLengthTest), or, if the value is never saved, add it to NOT_SAVED with the reason:\n".implode("\n", array_map(fn (string $key) => "  {$key}  ({$unbounded[$key]})", $missing)),
        );
    }

    public function test_every_line_of_the_list_still_names_a_rule_without_a_length(): void
    {
        $stale = array_values(array_diff(array_keys(self::NOT_SAVED + self::OPEN + self::LONG_COLUMN), array_keys($this->unbounded())));

        $this->assertSame([], $stale, "These are on the list but now say how long they can be (or are gone): take them off.\n".implode("\n", $stale));
    }

    public function test_the_scan_sees_the_rules_it_is_meant_to(): void
    {
        // A scan that reads nothing passes for ever. These two are written the two ways rules are written (an array, and a string with pipes).
        $all = RuleScanner::entries();

        $this->assertArrayHasKey('Requests/Admin/StoreForumBoardRequest.php::title', $all);
        $this->assertArrayHasKey('Requests/Admin/StoreFaqRequest.php::answer', $all);
        $this->assertStringContainsString('max:255', $all['Requests/Admin/StoreForumBoardRequest.php::title'][0]);
        $this->assertStringContainsString('max:16000', $all['Requests/Admin/StoreFaqRequest.php::answer'][0]);
        $this->assertGreaterThan(300, count($all), 'far fewer rules were found than the application has');
    }

    public function test_the_scan_names_what_it_should_in_a_file_it_is_given(): void
    {
        $directory = storage_path('framework/testing/string-rules-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($directory);

        try {
            File::put($directory.'/Sample.php', <<<'PHP'
<?php

class Sample
{
    public function store() { return ['title' => ['required', 'string', 'max:255'], 'note' => 'nullable|string|max:100']; }

    // The same field in the next action says nothing about length: it has to be found, whatever the first one says.
    public function update() { return ['title' => ['required', 'string'], 'note' => 'nullable|string|max:100']; }

    // A list of props with an `email` key is not a rule, and a value that is a closed set or a number is bounded.
    public function props() { return ['user' => ['email' => $user->email, 'id' => $user->id], 'kind' => ['required', 'in:a,b'], 'count' => 'integer']; }

    // An email address has a shape and no length.
    public function open() { return ['email' => 'required|email']; }
}
PHP);

            $this->assertEqualsCanonicalizing(['Sample.php::title', 'Sample.php::email'], array_keys($this->unbounded($directory)));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * `path::field` => the rule text, for every rule that takes text and gives no length.
     *
     * @return array<string, string>
     */
    private function unbounded(?string $base = null): array
    {
        $found = [];

        foreach (RuleScanner::entries($base) as $key => $rules) {
            foreach ($rules as $rule) {
                // `string` or `email` as a rule (`'string'`, `|string|`, `'email:rfc'`), and not as the key of a list of props (`'email' => $user->email`).
                $takesText = preg_match('/[\'|](?:string|email)(?:[\'|:](?!\s*=>))/', $rule) === 1;
                $bounded = preg_match('/max:|size:|between:|digits|[\'|]in:|Rule::in|Rule::enum|Enum|uuid|[\'|]date|timezone|exists|current_password|CountryCode|Password::|new [A-Z]/', $rule) === 1;

                if ($takesText && ! $bounded) {
                    $found[$key] ??= preg_replace('/\s+/', ' ', trim($rule));
                }
            }
        }

        return $found;
    }
}
