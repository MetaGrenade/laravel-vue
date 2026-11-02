<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Text that people wrote (a forum post, a blog article, a product description) is styled by the `.tiptap` rules in resources/css/app.css,
 * the same ones the editor uses. Tailwind's `prose` classes belong to its typography plugin, which this project does not have, so they
 * style nothing: preflight takes the bullets off every list, and a post that relied on them showed as plain lines.
 */
class RichTextStylesTest extends TestCase
{
    #[Test]
    public function no_page_uses_a_prose_class_because_nothing_defines_it(): void
    {
        if ($this->packageJson('@tailwindcss/typography')) {
            $this->markTestSkipped('The typography plugin is installed, so the prose classes are defined.');
        }

        $found = [];

        foreach ($this->frontEndFiles() as $path) {
            foreach (file($path) ?: [] as $number => $line) {
                if (preg_match('/(?<![\w-])prose(?!\w)/', $line) === 1) {
                    $found[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).':'.($number + 1);
                }
            }
        }

        $this->assertSame([], $found, 'These use a "prose" class, which styles nothing here. Use "tiptap" on text that people wrote: '.implode(', ', $found));
    }

    #[Test]
    public function the_rich_text_styles_give_lists_their_bullets_and_numbers(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertMatchesRegularExpression('/\.tiptap ul,\s*\.ProseMirror ul \{\s*@apply my-3 list-disc/', $css);
        $this->assertMatchesRegularExpression('/\.tiptap ol,\s*\.ProseMirror ol \{\s*@apply my-3 list-decimal/', $css);
    }

    /**
     * @return list<string>
     */
    private function frontEndFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (in_array($file->getExtension(), ['vue', 'ts'], true)) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function packageJson(string $package): bool
    {
        $json = json_decode((string) file_get_contents(base_path('package.json')), true);

        return isset($json['dependencies'][$package]) || isset($json['devDependencies'][$package]);
    }
}
