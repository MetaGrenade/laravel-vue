<?php

namespace Tests\Feature\Validation;

use Illuminate\Support\Facades\File;
use Tests\Support\RuleScanner;
use Tests\TestCase;

/**
 * A nickname or an email address is compared without regard to case, on every database (docs/upgrading.md, "Nicknames and email addresses
 * that differ only by case").
 *
 * MySQL and MariaDB do that on their own and SQLite and PostgreSQL do not, so a plain `unique` rule or `where('nickname', ...)` answers
 * differently depending on the database, and the tests (which run on SQLite) never see the answer of the one most sites use. This reads
 * `app/` and names the two ways of asking that leave the case to the database: a `unique` rule on a `nickname` or an `email`, and a query
 * that matches one of those columns with `where('email', ...)`. (Signing in and a password reset do not appear here: they go through
 * `EmailIgnoringCaseUserProvider`, which EmailLookupIgnoresCaseTest checks is the provider the site uses.)
 *
 * The list is for queries that ask something else, each with its reason. One that is not on it fails here, and a line on it that no longer
 * matches fails too, so the list cannot go stale.
 */
class NamesAreComparedIgnoringCaseTest extends TestCase
{
    /**
     * `path::the line` => why it may compare exactly.
     *
     * @var array<string, string>
     */
    private const EXACT = [
        'Support/Members/EmailLookup.php::$exact = User::query()->where(\'email\', $given)->first();' => 'the address as it was typed, tried first so that two accounts that differ by case each keep their own spelling; EmailLookup asks LOWER() next',
        'Http/Controllers/Auth/SocialLoginController.php::if (! User::query()->where(\'email\', $base)->exists()) {' => 'a placeholder address this code made, all lowercase and kept lowercase',
        'Http/Controllers/Auth/SocialLoginController.php::} while (User::query()->where(\'email\', $email)->exists());' => 'a placeholder address this code made, all lowercase and kept lowercase',
    ];

    public function test_no_rule_leaves_the_case_of_a_nickname_or_an_address_to_the_database(): void
    {
        $found = $this->plainUniqueRules();

        $this->assertSame(
            [],
            array_keys($found),
            "These rules use `unique` for a nickname or an email address, which compares without regard to case on MySQL and MariaDB only. Use UniqueIgnoringCase::in('users', 'nickname'):\n".implode("\n", array_map(fn (string $key) => "  {$key}  ({$found[$key]})", array_keys($found))),
        );
    }

    public function test_no_query_leaves_the_case_of_a_nickname_or_an_address_to_the_database(): void
    {
        $found = $this->exactQueries();
        $missing = array_values(array_diff(array_keys($found), array_keys(self::EXACT)));

        $this->assertSame(
            [],
            $missing,
            "These queries match a nickname or an email address exactly, which is what MySQL and MariaDB do not do. To ask whether a name is free use UniqueIgnoringCase::taken(), and to find the account an address names use EmailLookup::find(); if the query asks something else, add it to EXACT with the reason:\n".implode("\n", $missing),
        );
    }

    public function test_every_line_of_the_list_still_names_a_query(): void
    {
        $stale = array_values(array_diff(array_keys(self::EXACT), array_keys($this->exactQueries())));

        $this->assertSame([], $stale, "These are on the list but the query is gone or has changed: take them off, or update the line.\n".implode("\n", $stale));
    }

    public function test_the_scans_see_what_they_are_meant_to(): void
    {
        // A scan that reads nothing passes for ever.
        $rules = RuleScanner::entries();

        $this->assertArrayHasKey('Controllers/Auth/RegisteredUserController.php::nickname', $rules);
        $this->assertStringContainsString('UniqueIgnoringCase', $rules['Controllers/Auth/RegisteredUserController.php::email'][0]);
        $this->assertGreaterThanOrEqual(count(self::EXACT), count($this->exactQueries()));
        $this->assertNotEmpty($this->exactQueries());
    }

    public function test_the_scans_name_what_they_should_in_files_they_are_given(): void
    {
        $directory = storage_path('framework/testing/names-case-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($directory);

        try {
            File::put($directory.'/Sample.php', <<<'PHP'
<?php

class Sample
{
    public function store() { return ['nickname' => ['required', UniqueIgnoringCase::in('users', 'nickname')], 'email' => 'required|email']; }

    // The same field in the next action says nothing about case: it has to be found, whatever the first one says.
    public function update() { return ['nickname' => ['required', Rule::unique('users', 'nickname')->ignore(1)], 'slug' => 'unique:posts,slug']; }

    public function register() { return ['email' => 'required|unique:users,email']; }

    public function find($x) { $a = User::where('email', $x)->first(); $b = User::query()->where('slug', $x)->first(); $c = User::whereRaw('LOWER(email) = ?', [$x]); }
}
PHP);

            $this->assertEqualsCanonicalizing(['Sample.php::nickname', 'Sample.php::email'], array_keys($this->plainUniqueRules($directory)));
            $this->assertCount(1, $this->exactQueries($directory));
        } finally {
            File::deleteDirectory($directory);
        }
    }

    /**
     * `path::field` => the rule text, for every `unique` rule on a `nickname` or an `email` that is not UniqueIgnoringCase.
     *
     * @return array<string, string>
     */
    private function plainUniqueRules(?string $base = null): array
    {
        $found = [];

        foreach (RuleScanner::entries($base ?? app_path('Http')) as $key => $rules) {
            if (! preg_match('/::(nickname|email)$/', $key)) {
                continue;
            }

            foreach ($rules as $rule) {
                if (preg_match('/unique/i', $rule) === 1 && ! str_contains($rule, 'UniqueIgnoringCase')) {
                    $found[$key] ??= preg_replace('/\s+/', ' ', trim($rule));
                }
            }
        }

        return $found;
    }

    /**
     * `path::the line` => the line, for every query that matches the `nickname` or `email` column with `where('email', ...)`.
     *
     * @return array<string, string>
     */
    private function exactQueries(?string $base = null): array
    {
        $base ??= app_path();
        $found = [];

        foreach (File::allFiles($base) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $path = str_replace('\\', '/', $file->getRelativePathname());

            foreach (preg_split('/\R/', (string) file_get_contents($file->getPathname())) ?: [] as $line) {
                if (preg_match('/->where\(\s*[\'"](nickname|email)[\'"]\s*,|\bwhere\(\s*[\'"](nickname|email)[\'"]\s*,/', $line) === 1) {
                    $found["{$path}::".trim($line)] = trim($line);
                }
            }
        }

        return $found;
    }
}
