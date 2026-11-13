<?php

namespace App\Support\Json;

use LogicException;
use RuntimeException;

/**
 * One JSON object written to a stream a member at a time, in the bytes `json_encode($whole, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)` gives.
 *
 * `json_encode` needs the whole document in memory first, and for a data export that is every post a member has ever made, twice over (the array and
 * the string). Here a list member is written item by item as the items come, so what is held is one item. Each value is still encoded by
 * `json_encode` with the same flags, and only the indent and the commas between values are written here; a JSON string never holds a raw line break
 * (it is escaped), so a line of an encoded value can be pushed right by a plain prefix.
 *
 * Invalid UTF-8 in a value is replaced with U+FFFD. Left alone `json_encode` returns `false` for the *whole* document, which the export used to write
 * as an empty file. Any other failure throws, and the export fails with it instead of keeping a document that stops half way.
 */
final class StreamedObject
{
    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    private const INDENT = '    ';

    private int $members = 0;

    private bool $finished = false;

    /**
     * @param  resource  $handle
     */
    public function __construct(private $handle) {}

    /**
     * A member whose value is written whole: a scalar, or an array or object that is small.
     */
    public function member(string $key, mixed $value): void
    {
        $encoded = self::encode($value);

        $this->open($key);
        $this->write($this->indented($encoded, self::INDENT));
    }

    /**
     * A member that is a list, written one item at a time. An empty list is `[]`, as `json_encode` writes it.
     *
     * @param  iterable<mixed>  $items
     */
    public function list(string $key, iterable $items): void
    {
        $this->open($key);

        $count = 0;

        foreach ($items as $item) {
            $this->write(($count === 0 ? "[\n" : ",\n").self::INDENT.self::INDENT.$this->indented(self::encode($item), self::INDENT.self::INDENT));
            $count++;
        }

        $this->write($count === 0 ? '[]' : "\n".self::INDENT.']');
    }

    /**
     * Close the object. `{}` when nothing was written, as `json_encode` writes an empty object.
     */
    public function finish(): void
    {
        $this->guard();
        $this->finished = true;
        $this->write($this->members === 0 ? '{}' : "\n}");
    }

    private function open(string $key): void
    {
        $this->guard();
        $this->write(($this->members === 0 ? "{\n" : ",\n").self::INDENT.self::encode($key).': ');
        $this->members++;
    }

    private function guard(): void
    {
        if ($this->finished) {
            throw new LogicException('The JSON object is already finished.');
        }
    }

    private function indented(string $encoded, string $prefix): string
    {
        return str_replace("\n", "\n".$prefix, $encoded);
    }

    private function write(string $text): void
    {
        // A short write (a full disk) is a failure too: fwrite then returns fewer bytes than it was given.
        if (fwrite($this->handle, $text) !== strlen($text)) {
            throw new RuntimeException('Unable to write the JSON document.');
        }
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, self::FLAGS);
    }
}
