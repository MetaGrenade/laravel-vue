<?php

namespace Tests\Feature;

use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/configuration.md is the list of everything the site reads from the environment. A setting added to config/ without a row there
 * is a setting nobody finds, and a default that changed without the page being changed is a promise that is no longer true.
 */
class ConfigurationDocsTest extends TestCase
{
    private const PAGE = 'docs/configuration.md';

    #[Test]
    public function every_setting_read_in_config_has_a_row_in_the_reference(): void
    {
        $missing = array_values(array_diff(array_keys($this->configKeys()), array_keys($this->rows())));

        sort($missing);

        $this->assertSame([], $missing, "These keys are read in config/ and have no row in docs/configuration.md. Add a row\n| `KEY` | default | what it does |\nto the section they belong to:\n  ".implode("\n  ", $missing));
    }

    #[Test]
    public function every_key_in_the_env_example_has_a_row_too(): void
    {
        $missing = array_values(array_diff($this->exampleKeys(), array_keys($this->rows())));

        sort($missing);

        $this->assertSame([], $missing, "These keys are in .env.example and have no row in docs/configuration.md:\n  ".implode("\n  ", $missing));
    }

    #[Test]
    public function the_reference_lists_nothing_that_nothing_reads(): void
    {
        $known = array_merge(array_keys($this->configKeys()), $this->exampleKeys(), $this->frameworkKeys(), $this->browserKeys());
        $unknown = array_values(array_diff(array_keys($this->rows()), $known));

        sort($unknown);

        $this->assertSame([], $unknown, "These keys are in docs/configuration.md and are read by no config file, no .env.example line, no Laravel default and no browser code (a typo, or a setting that was removed):\n  ".implode("\n  ", $unknown));
    }

    #[Test]
    public function a_key_has_one_row(): void
    {
        $counts = array_count_values(array_column($this->tableLines(), 'key'));
        $twice = array_keys(array_filter($counts, fn (int $count) => $count > 1));

        sort($twice);

        $this->assertSame([], $twice, "These keys have more than one row in docs/configuration.md; give each one row and mention where else it matters in its text:\n  ".implode("\n  ", $twice));
    }

    #[Test]
    public function a_default_written_as_code_is_the_default_in_config(): void
    {
        $keys = $this->configKeys();
        $wrong = [];

        foreach ($this->rows() as $key => $default) {
            if ($default === null || ! isset($keys[$key])) {
                continue;
            }

            // A key read in several files (DB_PORT, LOG_LEVEL) can have a different default in each: the page may name any of them.
            $plain = array_values(array_filter(
                array_unique($keys[$key]['defaults']),
                fn (?string $one) => $one !== null && $one !== self::COMPUTED,
            ));

            if ($plain === []) {
                $wrong[] = "{$key}: config/ has no plain default for it ({$this->describe($keys[$key]['defaults'])}); write the default in words, not as `code`";

                continue;
            }

            if (! in_array($default, $plain, true)) {
                $wrong[] = "{$key}: the page says `{$default}`, config/ says ".implode(' or ', array_map(fn (string $one) => "`{$one}`", $plain));
            }
        }

        $this->assertSame([], $wrong, "A default that is written as `code` in docs/configuration.md has to be the one in config/:\n  ".implode("\n  ", $wrong));
    }

    /** What the tokenizer cannot read as one plain value: a call, a sum, a constant. */
    private const COMPUTED = '(computed)';

    /**
     * @param  list<string|null>  $defaults
     */
    private function describe(array $defaults): string
    {
        return implode(' / ', array_map(fn ($default) => $default ?? 'no default', array_unique($defaults)));
    }

    /**
     * The `| `KEY` | default | text |` lines of the tables that start `| Key | Default |`. Other tables on the page (the checklist for
     * going live) name settings too, and are not rows of the reference.
     *
     * @return list<array{key: string, default: string|null}>
     */
    private function tableLines(): array
    {
        $page = (string) file_get_contents(base_path(self::PAGE));
        $lines = [];
        $inReference = false;

        foreach (preg_split('/\R/', $page) ?: [] as $line) {
            if (! str_starts_with($line, '|')) {
                $inReference = false;

                continue;
            }

            if (preg_match('/^\|\s*Key\s*\|\s*Default\s*\|/', $line)) {
                $inReference = true;

                continue;
            }

            if ($inReference && preg_match('/^\|\s*`([A-Z][A-Z0-9_]*)`\s*\|\s*(.*?)\s*\|(.*)\|\s*$/', $line, $match)) {
                $lines[] = [
                    'key' => $match[1],
                    // A default in backticks is a claim that is checked; anything else (words, a dash) is not.
                    'default' => preg_match('/^`([^`]*)`$/', $match[2], $code) ? $code[1] : null,
                ];
            }
        }

        return $lines;
    }

    /**
     * @return array<string, string|null> key => the default written as code, or null
     */
    private function rows(): array
    {
        $rows = [];

        foreach ($this->tableLines() as $line) {
            $rows[$line['key']] = $line['default'];
        }

        return $rows;
    }

    /**
     * Every `env('KEY', default)` call in the code of the config files (not in what is commented out), with the default when it is
     * one plain value.
     *
     * @return array<string, array{defaults: list<string|null>}>
     */
    private function configKeys(): array
    {
        return $this->envCalls(glob(config_path('*.php')) ?: []);
    }

    /**
     * What Laravel's own configuration reads for the files this project does not have (hashing, for one).
     *
     * @return list<string>
     */
    private function frameworkKeys(): array
    {
        return array_keys($this->envCalls(glob(base_path('vendor/laravel/framework/config/*.php')) ?: []));
    }

    /**
     * @param  list<string>  $files
     * @return array<string, array{defaults: list<string|null>}>
     */
    private function envCalls(array $files): array
    {
        $found = [];

        foreach ($files as $file) {
            $tokens = array_values(array_filter(
                PhpToken::tokenize((string) file_get_contents($file)),
                fn (PhpToken $token) => ! $token->isIgnorable(),
            ));

            foreach ($tokens as $at => $token) {
                if (! $this->isEnvCall($tokens, $at)) {
                    continue;
                }

                $key = trim($tokens[$at + 2]->text, '\'"');
                $found[$key]['defaults'][] = $this->defaultAfter($tokens, $at + 3);
            }
        }

        return $found;
    }

    /**
     * Whether the token at $at starts `env('KEY'`, or a call of a helper variable that reads the environment for a config file
     * (config/discord.php has `$discordEventClassesFromEnv('KEY', 'default')`, which hides the key from a search for `env(`). The
     * helper is recognised by a variable name that contains "env" and a key written in capitals.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function isEnvCall(array $tokens, int $at): bool
    {
        $token = $tokens[$at];

        if (! ($tokens[$at + 1] ?? null)?->is('(') || ! ($tokens[$at + 2] ?? null)?->is(T_CONSTANT_ENCAPSED_STRING)) {
            return false;
        }

        if ($token->is(T_STRING)) {
            return strtolower($token->text) === 'env';
        }

        return $token->is(T_VARIABLE)
            && str_contains(strtolower($token->text), 'env')
            && preg_match('/^[A-Z][A-Z0-9_]*$/', trim($tokens[$at + 2]->text, '\'"')) === 1;
    }

    /**
     * The default of the env() call whose key ends just before $from: null when there is none (or it is null), the plain value as
     * text when it is one string, number, true or false, and COMPUTED for anything else.
     *
     * @param  list<PhpToken>  $tokens
     */
    private function defaultAfter(array $tokens, int $from): ?string
    {
        if (! ($tokens[$from] ?? null)?->is(',')) {
            return null;
        }

        $depth = 0;
        $default = [];

        for ($i = $from + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if ($depth === 0 && $token->is([')', ','])) {
                break;
            }

            if ($token->is(['(', '[']) || $token->is(T_CURLY_OPEN)) {
                $depth++;
            } elseif ($token->is([')', ']', '}'])) {
                $depth--;
            }

            $default[] = $token;
        }

        if (count($default) !== 1) {
            return self::COMPUTED;
        }

        $token = $default[0];

        return match (true) {
            $token->is(T_CONSTANT_ENCAPSED_STRING) => str_replace(['\\\'', '\\\\'], ['\'', '\\'], substr($token->text, 1, -1)),
            $token->is([T_LNUMBER, T_DNUMBER]) => str_replace('_', '', $token->text),
            $token->is(T_STRING) && in_array(strtolower($token->text), ['true', 'false'], true) => strtolower($token->text),
            $token->is(T_STRING) && strtolower($token->text) === 'null' => null,
            default => self::COMPUTED,
        };
    }

    /**
     * @return list<string>
     */
    private function exampleKeys(): array
    {
        preg_match_all('/^#?\s*([A-Z][A-Z0-9_]*)=/m', (string) file_get_contents(base_path('.env.example')), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The settings the browser build reads (import.meta.env.VITE_...).
     *
     * @return list<string>
     */
    private function browserKeys(): array
    {
        $keys = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('js'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (! in_array($file->getExtension(), ['ts', 'vue', 'js'], true)) {
                continue;
            }

            preg_match_all('/import\.meta\.env\.(VITE_[A-Z0-9_]+)/', (string) file_get_contents($file->getPathname()), $matches);
            array_push($keys, ...$matches[1]);
        }

        return array_values(array_unique($keys));
    }
}
