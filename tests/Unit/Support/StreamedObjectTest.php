<?php

namespace Tests\Unit\Support;

use App\Support\Json\StreamedObject;
use Generator;
use JsonException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The data export's `export.json` is written a member at a time, and has to be the bytes `json_encode` gives for the whole document.
 */
class StreamedObjectTest extends TestCase
{
    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE;

    /**
     * @param  callable(StreamedObject): void  $write
     */
    private function written(callable $write): string
    {
        $handle = fopen('php://temp', 'r+');
        $document = new StreamedObject($handle);
        $write($document);
        $document->finish();
        rewind($handle);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    /**
     * Each document, and which of its members are written as lists (item by item); the others are written whole.
     *
     * @return array<string, array{0: array<string, mixed>, 1: list<string>}>
     */
    public static function documents(): array
    {
        $item = ['id' => 1, 'body' => "A \"quoted\" word, a back\slash, a / slash,\nand a line break, é 日本語 😀", 'tags' => ['a', 'b'], 'none' => null, 'nested' => ['x' => [], 'y' => ['z' => 1.5]]];

        return [
            'scalars' => [['a' => 1, 'b' => 'two', 'c' => true, 'd' => null, 'e' => 3.25], []],
            'an empty list' => [['items' => []], ['items']],
            'an empty array written whole' => [['items' => []], []],
            'one item' => [['items' => [$item]], ['items']],
            'many items, with scalars and lists among them' => [['items' => [$item, $item, ['id' => 2], 7, 'text', [1, 2, 3], []]], ['items']],
            'a whole member and then lists, as the export is' => [[
                'user' => ['id' => 4, 'email' => 'a@b.test', 'social_links' => ['github' => 'https://github.com/a/b?x=1&y=2']],
                'profile' => ['bio' => null, 'social_links' => null],
                'blog_comments' => [$item, $item],
                'forum_posts' => [],
                'usage' => [['meter' => 'api.requests', 'date' => '2026-11-12', 'units' => 3]],
            ], ['blog_comments', 'forum_posts', 'usage']],
            'keys that need escaping' => [['a "key"' => 1, 'é' => 2, 'a/b' => ['c\d' => 3]], []],
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @param  list<string>  $lists
     */
    #[Test]
    #[DataProvider('documents')]
    public function it_writes_the_bytes_json_encode_gives_for_the_whole_document(array $document, array $lists): void
    {
        $text = $this->written(function (StreamedObject $json) use ($document, $lists): void {
            foreach ($document as $key => $value) {
                in_array($key, $lists, true) ? $json->list($key, $value) : $json->member($key, $value);
            }
        });

        $this->assertSame(json_encode($document, self::FLAGS), $text);
    }

    #[Test]
    public function an_empty_object_and_an_empty_list_are_written_as_json_encode_writes_them(): void
    {
        $this->assertSame('{}', $this->written(fn (StreamedObject $json) => null));
        $this->assertSame("{\n    \"items\": []\n}", $this->written(fn (StreamedObject $json) => $json->list('items', [])));
        $this->assertSame(json_encode(['items' => []], self::FLAGS), $this->written(fn (StreamedObject $json) => $json->list('items', [])));
    }

    #[Test]
    public function a_list_is_written_as_its_items_come_and_not_when_it_is_over(): void
    {
        $handle = fopen('php://temp', 'r+');
        $document = new StreamedObject($handle);

        $seen = [];
        $items = (function () use (&$seen, $handle): Generator {
            foreach ([1, 2, 3] as $n) {
                // How much has been written by the time the next item is asked for.
                $seen[] = ftell($handle);

                yield ['n' => $n];
            }
        })();

        $document->list('items', $items);
        $document->finish();

        $this->assertGreaterThan(0, $seen[0], 'the key is written before the first item');
        $this->assertGreaterThan($seen[0], $seen[1], 'the first item is written before the second is asked for');
        $this->assertGreaterThan($seen[1], $seen[2], 'and the second before the third');

        rewind($handle);
        $this->assertSame(json_encode(['items' => [['n' => 1], ['n' => 2], ['n' => 3]]], self::FLAGS), stream_get_contents($handle));
    }

    #[Test]
    public function the_text_it_writes_reads_back_as_the_document(): void
    {
        $document = ['user' => ['id' => 1], 'items' => [['id' => 1, 'body' => "line\nbreak \"q\" \\ é"], ['id' => 2, 'body' => '']], 'none' => []];

        $text = $this->written(function (StreamedObject $json) use ($document): void {
            $json->member('user', $document['user']);
            $json->list('items', $document['items']);
            $json->member('none', []);
        });

        $this->assertSame($document, json_decode($text, true, flags: JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function invalid_utf8_is_replaced_and_does_not_empty_the_document(): void
    {
        // json_encode returns false for a whole document with one bad byte in it; the export used to write that as an empty file.
        $text = $this->written(fn (StreamedObject $json) => $json->list('items', [['body' => "ok \xB1\x31 end"]]));

        $this->assertSame("ok \u{FFFD}1 end", json_decode($text, true, flags: JSON_THROW_ON_ERROR)['items'][0]['body']);
    }

    #[Test]
    public function a_value_that_cannot_be_encoded_throws_and_writes_nothing_for_that_member(): void
    {
        $handle = fopen('php://temp', 'r+');
        $document = new StreamedObject($handle);

        $this->expectException(JsonException::class);

        try {
            $document->member('number', NAN);
        } finally {
            rewind($handle);
            $this->assertSame('', stream_get_contents($handle), 'nothing was written for the member that failed');
        }
    }

    #[Test]
    public function a_finished_object_takes_nothing_more(): void
    {
        $document = new StreamedObject(fopen('php://temp', 'r+'));
        $document->finish();

        $this->expectException(LogicException::class);

        $document->member('late', 1);
    }

    #[Test]
    public function a_write_that_fails_is_an_error(): void
    {
        // A stream opened for reading only: fwrite gives back false.
        $document = new StreamedObject(fopen('php://memory', 'r'));

        $this->expectException(RuntimeException::class);

        @$document->member('a', 1);
    }
}
