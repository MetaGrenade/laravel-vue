<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A number in `.env` left blank (`SEARCH_TIMEOUT=`) is the default, not 0 (App\Support\Config\EnvNumber).
 *
 * `(int) env('SEARCH_TIMEOUT', 5)` made it 0, which for a timeout is no limit, for `retry_after` runs a job twice at once, for a session
 * lifetime signs nobody in, and for HSTS and a webhook tolerance switches the protection off. Two things hold that in place: every number
 * the config files read goes through the helper (a scan), and a site started with every one of them blank is the same site as one
 * started with every one at its default (the real files, in a child process, so nothing a `.env` on this machine says can answer).
 *
 * The exception is a number whose documentation says what an empty line means (BLANK_IS_AN_ANSWER, one setting: `API_SIGN_IN_EXPIRY_DAYS`,
 * "empty or 0 is no end"). The first version of this rule made every blank the default and so undid that one; each exception is now
 * written in the config file (`whenBlank:`), listed here, and has to be said in its row of docs/configuration.md.
 */
class BlankNumbersAreDefaultsTest extends TestCase
{
    /**
     * The settings whose documentation gives an empty line a meaning of its own, and so are not the default when blank: the config
     * path they end up in, and what the empty line is. A `whenBlank:` in a config file that is not listed here fails a test, and so
     * does a listed one whose row in docs/configuration.md does not say what an empty line means.
     *
     * @var array<string, array{path: string, blank: int}>
     */
    private const BLANK_IS_AN_ANSWER = [
        // "Empty or 0 is no end, where API_KEYS_ALLOW_NO_EXPIRY allows it" (docs/configuration.md, docs/api-keys.md).
        'API_SIGN_IN_EXPIRY_DAYS' => ['path' => 'api.sign_in.expiry_days', 'blank' => 0],
    ];

    /**
     * Every setting a config file reads as a number through the helper, with its default and what it says an empty line is (if it does).
     *
     * @return array<string, array{default: string, whenBlank: string|null}>
     */
    private function numberCalls(): array
    {
        $settings = [];

        foreach (glob(config_path('*.php')) ?: [] as $file) {
            preg_match_all("/EnvNumber::(?:integer|float)\\('([A-Z][A-Z0-9_]*)',\\s*(-?[0-9][0-9_.]*)(?:,\\s*whenBlank:\\s*(-?[0-9]+))?\\)/", (string) file_get_contents($file), $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $settings[$match[1]] = ['default' => str_replace('_', '', $match[2]), 'whenBlank' => ($match[3] ?? '') === '' ? null : $match[3]];
            }
        }

        ksort($settings);

        return $settings;
    }

    /**
     * Every setting a config file reads as a number through the helper, with its default.
     *
     * @return array<string, string>
     */
    private function numberSettings(): array
    {
        return array_map(fn (array $call) => $call['default'], $this->numberCalls());
    }

    /**
     * Start the application in a child process with a `.env` made of $lines (the machine's own is not read), and give back what it
     * read for $probe and every value of its configuration.
     *
     * @param  list<string>  $lines
     * @param  list<string>  $unset  names taken out of the environment the child inherits, so only the file says anything about them
     * @return array{raw: mixed, config: array<string, mixed>}
     */
    private function bootWith(array $lines, string $probe, array $unset): array
    {
        $dir = storage_path('framework/testing/blank-numbers-'.bin2hex(random_bytes(4)));
        mkdir($dir, 0777, true);
        file_put_contents($dir.'/.env', implode("\n", $lines)."\n");

        try {
            $result = Process::path(base_path())->env(array_fill_keys($unset, false) + [
                'BLANK_NUMBERS_ENV_DIR' => $dir,
                'BLANK_NUMBERS_PROBE' => $probe,
                'APP_CONFIG_CACHE' => $dir.'/no-config-cache.php',
                // Nothing in this process may reach the database of the machine it runs on.
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => ':memory:',
                'DB_URL' => '',
                'CACHE_STORE' => 'array',
                'SESSION_DRIVER' => 'array',
            ])->run([
                PHP_BINARY,
                '-r',
                'require "vendor/autoload.php"; $app = require "bootstrap/app.php"; $app->useEnvironmentPath(getenv("BLANK_NUMBERS_ENV_DIR"));'
                .' $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
                .' echo json_encode(["raw" => Illuminate\Support\Env::get(getenv("BLANK_NUMBERS_PROBE")), "config" => config()->all()], JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);',
            ]);
        } finally {
            @unlink($dir.'/.env');
            @rmdir($dir);
        }

        $this->assertTrue($result->successful(), $result->errorOutput().$result->output());

        $decoded = json_decode($result->output(), true);
        $this->assertIsArray($decoded, 'The application did not start: '.$result->errorOutput().substr($result->output(), 0, 500));

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> the values of every setting of a (nested) configuration, by `file.path.to.setting`
     */
    private function flatten(array $values, string $prefix = ''): array
    {
        $flat = [];

        foreach ($values as $key => $value) {
            if (is_array($value) && $value !== []) {
                $flat += $this->flatten($value, $prefix.$key.'.');
            } else {
                $flat[$prefix.$key] = $value;
            }
        }

        return $flat;
    }

    #[Test]
    public function a_site_started_with_every_number_blank_is_the_site_started_with_every_number_at_its_default(): void
    {
        $settings = $this->numberSettings();
        $this->assertGreaterThan(50, count($settings), 'The scan found hardly any numbers: has the helper been renamed?');

        $names = array_keys($settings);
        $probe = $names[0];

        $atDefaults = $this->bootWith(array_map(fn (string $key) => $key.'='.$settings[$key], $names), $probe, $names);
        $blank = $this->bootWith(array_map(fn (string $key) => $key.'=', $names), $probe, $names);

        // The blank lines did arrive as blank lines, so what follows is not a comparison of two identical starts.
        $this->assertSame($settings[$probe], $atDefaults['raw']);
        $this->assertSame('', $blank['raw']);

        $atDefaults = $this->flatten($atDefaults['config']);
        $blank = $this->flatten($blank['config']);

        // The settings whose documentation says what an empty line is: that, and nothing else, is what they come out as.
        foreach (self::BLANK_IS_AN_ANSWER as $answer) {
            $this->assertSame($answer['blank'], $blank[$answer['path']] ?? null, "{$answer['path']} is not what its documentation says an empty line is");
            $atDefaults[$answer['path']] = $answer['blank'];
        }

        $differ = [];
        foreach ($blank as $path => $value) {
            if (($atDefaults[$path] ?? null) !== $value) {
                $differ[] = $path.': '.var_export($atDefaults[$path] ?? null, true).' at its default, '.var_export($value, true).' blank';
            }
        }

        $this->assertSame([], $differ, "These settings are not their default when the line is blank:\n".implode("\n", $differ));
    }

    #[Test]
    public function only_the_settings_listed_here_give_an_empty_line_a_meaning_of_its_own(): void
    {
        $withAMeaning = array_keys(array_filter($this->numberCalls(), fn (array $call) => $call['whenBlank'] !== null));
        sort($withAMeaning);

        $listed = array_keys(self::BLANK_IS_AN_ANSWER);
        sort($listed);

        $this->assertSame($listed, $withAMeaning, 'A setting that says what an empty line means (`whenBlank:`) has to be listed in BLANK_IS_AN_ANSWER with the config path it ends up in, and its documentation has to say so.');

        foreach ($this->numberCalls() as $key => $call) {
            if ($call['whenBlank'] !== null) {
                $this->assertSame((string) self::BLANK_IS_AN_ANSWER[$key]['blank'], $call['whenBlank'], "$key: the config file and BLANK_IS_AN_ANSWER disagree about what an empty line is");
            }
        }
    }

    #[Test]
    public function a_setting_with_a_meaning_for_an_empty_line_says_so_in_the_reference(): void
    {
        $page = (string) file_get_contents(base_path('docs/configuration.md'));

        foreach (array_keys(self::BLANK_IS_AN_ANSWER) as $key) {
            $this->assertMatchesRegularExpression('/^\\| `'.$key.'` \\|[^|]*\\|[^|]*\\b[Ee]mpty\\b/m', $page, "$key gives an empty line a meaning, and its row in docs/configuration.md does not say what it is");
        }
    }

    #[Test]
    public function the_helper_does_not_change_what_a_number_is_when_it_is_written_out(): void
    {
        $settings = $this->numberSettings();
        $names = array_keys($settings);

        // Written out, every number comes back as a number the file asked for: 7 for each, or 7.0 where a fraction is allowed.
        $written = $this->flatten($this->bootWith(array_map(fn (string $key) => $key.'=7', $names), $names[0], $names)['config']);

        $this->assertSame(7, $written['api.keys.max_active']);
        $this->assertSame(7, $written['search.http.timeout_seconds']);
        $this->assertSame(7, $written['queue.connections.database.retry_after']);
        $this->assertSame(7, $written['session.lifetime']);
        $this->assertSame(7, $written['security.hsts.max_age']);
        $this->assertSame(7.0, $written['mail.mailers.smtp.timeout']);
    }

    /**
     * The numbers a config file reads that do not go through the helper: `(int) env('KEY', 5)`, `(float) env(...)` and
     * `env('KEY', 5)` (which is a number only when the line is absent, and an empty text when it is blank).
     *
     * @param  list<string>  $files
     * @return list<string>
     */
    private function numbersThatSkipTheHelper(array $files): array
    {
        $found = [];

        foreach ($files as $file) {
            // Comments are not code: a config file may show a call in one.
            $tokens = array_values(array_filter(
                PhpToken::tokenize((string) file_get_contents($file)),
                fn (PhpToken $token) => ! $token->isIgnorable(),
            ));

            foreach ($tokens as $at => $token) {
                if (! $token->is(T_STRING) || strtolower($token->text) !== 'env' || ! ($tokens[$at + 1] ?? null)?->is('(')) {
                    continue;
                }

                // Not a method or a static call that happens to be called env.
                if (($tokens[$at - 1] ?? null)?->is([T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION])) {
                    continue;
                }

                $key = trim(($tokens[$at + 2] ?? null)?->text ?? '', '\'"');
                $cast = ($tokens[$at - 1] ?? null)?->is([T_INT_CAST, T_DOUBLE_CAST]) === true;

                // The default: a number, possibly with a minus in front.
                $default = ($tokens[$at + 3] ?? null)?->is(',') ? ($tokens[$at + 4] ?? null) : null;
                $default = $default?->is('-') ? ($tokens[$at + 5] ?? null) : $default;
                $number = $default?->is([T_LNUMBER, T_DNUMBER]) === true;

                if ($cast || $number) {
                    $found[] = basename($file).': env(\''.$key.'\') is read as a number without EnvNumber';
                }
            }
        }

        return $found;
    }

    #[Test]
    public function every_number_a_config_file_reads_goes_through_the_helper(): void
    {
        $this->assertSame(
            [],
            $this->numbersThatSkipTheHelper(glob(config_path('*.php')) ?: []),
            'A blank `KEY=` makes these 0 (or an empty text). Read them with App\Support\Config\EnvNumber::integer() or ::float().',
        );
    }

    #[Test]
    public function the_scan_finds_a_number_that_skips_the_helper(): void
    {
        // Not in config/: every request the site gets loads every file in that folder.
        is_dir(storage_path('framework/testing')) || mkdir(storage_path('framework/testing'), 0777, true);
        $file = storage_path('framework/testing/scan-probe-'.bin2hex(random_bytes(4)).'.php');
        file_put_contents($file, "<?php\n\nreturn [\n    'a' => (int) env('PROBE_A', 5),\n    'b' => env('PROBE_B', 5),\n    'c' => max(1, (int) env('PROBE_C', 5)),\n    'd' => env('PROBE_D', 'text'),\n    'e' => env('PROBE_E'),\n    // 'f' => (int) env('PROBE_F', 5),\n    'g' => (float) env('PROBE_G', -1.5),\n    'h' => env('PROBE_H', '3306'),\n    'i' => \\App\\Support\\Config\\EnvNumber::integer('PROBE_I', 5),\n    'j' => (int) env('PROBE_J'),\n];\n");

        try {
            $found = $this->numbersThatSkipTheHelper([$file]);
        } finally {
            unlink($file);
        }

        $this->assertSame([
            basename($file).": env('PROBE_A') is read as a number without EnvNumber",
            basename($file).": env('PROBE_B') is read as a number without EnvNumber",
            basename($file).": env('PROBE_C') is read as a number without EnvNumber",
            basename($file).": env('PROBE_G') is read as a number without EnvNumber",
            basename($file).": env('PROBE_J') is read as a number without EnvNumber",
        ], $found);
    }
}
