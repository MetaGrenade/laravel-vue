<?php

namespace Tests\Feature;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * What the server says is in language keys too (docs/i18n.md): a message of an email, a form that was refused, an error page. A key that
 * is missing from the language file reaches the person as the key itself, and a value that a sentence asks for and is not given as
 * ":name" in the middle of it, which nothing else would notice. This reads the PHP and the Blade views of the app for the calls that
 * name a key and checks each of them against `lang/en`.
 */
class ServerTranslationKeysTest extends TestCase
{
    /**
     * What the validator puts into a message itself, from the rule and the field it failed on: the sentence for `min:20` may ask for
     * ":min" and the form's `messages()` does not give it.
     */
    private const FILLED_BY_THE_VALIDATOR = ['attribute', 'input', 'min', 'max', 'size', 'other', 'value', 'values', 'date', 'format', 'digits', 'decimal'];

    #[Test]
    public function every_key_the_server_uses_is_in_the_language_files_and_is_given_the_values_it_asks_for(): void
    {
        $groups = collect(File::files(lang_path('en')))->map(fn ($file) => $file->getFilenameWithoutExtension())->all();

        $problems = [];
        $checked = 0;

        $finder = (new Finder)->files()->in([app_path(), resource_path('views')])->name('*.php');

        foreach ($finder as $file) {
            foreach ($this->calls($file->getContents(), $groups) as [$function, $key, $values]) {
                $checked++;

                if (! Lang::has($key, 'en', false)) {
                    $problems[] = "{$file->getRelativePathname()}: {$key} is not in lang/en";

                    continue;
                }

                // The validator fills ":attribute" and the rest in itself, whatever the call.
                if ($values === null || str_starts_with($key, 'validation.')) {
                    continue;
                }

                $asked = [];

                foreach (explode('|', (string) Lang::get($key, [], 'en', false)) as $form) {
                    preg_match_all('/(?<![A-Za-z0-9]):([a-z][a-z_]*)/', $form, $found);
                    $asked = array_merge($asked, $found[1]);
                }

                // A value that is given and not asked for is harmless (the framework gives `auth.throttle` both :seconds and :minutes so
                // that a language can say either); one that is asked for and not given reaches the person as ":name" in a sentence.
                $notGiven = array_values(array_diff(
                    array_unique($asked),
                    $values,
                    $function === 'trans_choice' ? ['count'] : [],
                    self::FILLED_BY_THE_VALIDATOR,
                ));

                if ($notGiven !== []) {
                    $problems[] = "{$file->getRelativePathname()}: {$key} asks for [".implode(', ', $notGiven).'] and is given ['.implode(', ', $values).']';
                }
            }
        }

        $this->assertGreaterThan(250, $checked, 'the app was not read');
        $this->assertSame([], $problems, implode("\n", $problems));
    }

    #[Test]
    public function the_copies_of_the_frameworks_language_files_have_every_key_the_framework_has(): void
    {
        // Laravel reads its own English under the app's, so a key missing from the copy still shows in English, and `lang:check` would
        // never ask a language for it. After an upgrade that adds a rule or an answer, the new key has to be copied in.
        $missing = [];

        foreach (['validation', 'passwords'] as $group) {
            $framework = require base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/en/{$group}.php");
            $ours = require lang_path("en/{$group}.php");

            $keys = array_filter(array_keys(Arr::dot($framework)), fn (string $key) => ! str_starts_with($key, 'custom.'));

            foreach (array_diff($keys, array_keys(Arr::dot($ours))) as $key) {
                $missing[] = "{$group}.{$key}";
            }
        }

        $this->assertSame([], $missing, "Keys of the framework's lang/en that lang/en here does not have:\n".implode("\n", $missing));
    }

    /**
     * The calls in some PHP that name a key of one of the groups and write it out: `__('mail.x', [...])`, `trans('auth.x')` and
     * `trans_choice('mail.x', $n, [...])`, with the names of the values each is given (null when they are not an array written out).
     *
     * @param  list<string>  $groups
     * @return list<array{0: string, 1: string, 2: list<string>|null}>
     */
    private function calls(string $php, array $groups): array
    {
        $calls = [];
        $pattern = "/\\b(__|trans_choice|trans)\\(\\s*'((?:".implode('|', array_map('preg_quote', $groups)).")\\.[a-z0-9_.]+)'/";

        preg_match_all($pattern, $php, $starts, PREG_OFFSET_CAPTURE);

        foreach ($starts[0] as $index => [$text, $offset]) {
            $function = $starts[1][$index][0];
            $key = $starts[2][$index][0];
            $open = strpos($php, '(', $offset);
            $arguments = $this->arguments($php, $open);
            $given = $arguments[$function === 'trans_choice' ? 2 : 1] ?? null;
            $values = [];

            if ($given !== null) {
                $values = preg_match('/^\s*\[(.*)\]\s*$/s', $given, $array) === 1 ? $this->arrayKeys($array[1]) : null;
            }

            $calls[] = [$function, $key, $values];
        }

        return $calls;
    }

    /**
     * The arguments of the call whose `(` is at `$open`, as the text of each.
     *
     * @return list<string>
     */
    private function arguments(string $php, int $open): array
    {
        $arguments = [];
        $depth = 0;
        $quote = null;
        $start = $open + 1;

        for ($i = $open; $i < strlen($php); $i++) {
            $char = $php[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(' || $char === '[') {
                $depth++;
            } elseif ($char === ')' || $char === ']') {
                $depth--;

                if ($depth === 0) {
                    $arguments[] = substr($php, $start, $i - $start);

                    return $arguments;
                }
            } elseif ($char === ',' && $depth === 1) {
                $arguments[] = substr($php, $start, $i - $start);
                $start = $i + 1;
            }
        }

        return $arguments;
    }

    /**
     * The keys of the entries of an array written out, at its own level: `'a' => f(['b' => 1]), 'c' => 2` has `a` and `c`.
     *
     * @return list<string>
     */
    private function arrayKeys(string $inside): array
    {
        $keys = [];

        foreach ($this->arguments('('.$inside.')', 0) as $entry) {
            if (preg_match("/^\\s*'([a-z_]+)'\\s*=>/", $entry, $key) === 1) {
                $keys[] = $key[1];
            }
        }

        return $keys;
    }
}
