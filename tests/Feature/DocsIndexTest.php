<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * docs/README.md is the way into the documentation: every guide, grouped by what a reader is trying to do. A page that is written and
 * not listed there is a page nobody finds, and a line for a page that was renamed or removed sends a reader nowhere.
 */
class DocsIndexTest extends TestCase
{
    private const INDEX = 'docs/README.md';

    #[Test]
    public function every_page_in_docs_is_listed_in_the_index(): void
    {
        $listed = array_column($this->entries(), 'page');
        $missing = [];

        foreach (glob(base_path('docs/*.md')) as $path) {
            $page = basename($path);

            if ($page !== 'README.md' && ! in_array($page, $listed, true)) {
                $missing[] = $page;
            }
        }

        sort($missing);

        $this->assertGreaterThan(40, count($listed), 'The test found hardly any entries in docs/README.md, so it is not reading it.');
        $this->assertSame([], $missing, "These pages in docs/ are not listed in docs/README.md. Add a line\n- [Title](page.md): what it is for\nunder the heading of the group they belong to:\n  ".implode("\n  ", $missing));
    }

    #[Test]
    public function every_entry_names_a_page_that_exists_once_and_says_what_it_is_for(): void
    {
        $problems = [];
        $seen = [];

        foreach ($this->entries() as $entry) {
            if (! is_file(base_path('docs/'.$entry['page']))) {
                $problems[] = "line {$entry['line']}: docs/{$entry['page']} does not exist (renamed, moved or removed)";
            }

            if (isset($seen[$entry['page']])) {
                $problems[] = "line {$entry['line']}: {$entry['page']} is listed again (first on line {$seen[$entry['page']]}); put it under one heading";
            }

            $seen[$entry['page']] ??= $entry['line'];

            if (strlen($entry['says']) < 15) {
                $problems[] = "line {$entry['line']}: {$entry['page']} has no description; say what the page is for after the colon";
            }
        }

        $this->assertSame([], $problems, "docs/README.md:\n  ".implode("\n  ", $problems));
    }

    /**
     * The list items of the index that link to a page of docs/: the page, the line and the words after the link.
     *
     * @return list<array{page: string, line: int, says: string}>
     */
    private function entries(): array
    {
        $entries = [];

        foreach (explode("\n", (string) file_get_contents(base_path(self::INDEX))) as $number => $line) {
            if (preg_match('/^- \[[^\]]+\]\(([^)#\/]+\.md)\)(?::\s*(.*))?$/', $line, $match) === 1) {
                $entries[] = ['page' => $match[1], 'line' => $number + 1, 'says' => $match[2] ?? ''];
            }
        }

        return $entries;
    }
}
