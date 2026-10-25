<?php

namespace App\Support\Mail;

/**
 * Text somebody typed, put in an email (docs/email.md). The lines of a notification are read as Markdown, so a `[link](https://...)`
 * or a `*` in what a moderator wrote would become a link or emphasis in the email.
 */
final class MailText
{
    /**
     * The text as a quotation, every line of it shown as the words it is.
     */
    public static function quoted(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        return implode("\n", array_map(fn (string $line) => '> '.self::escape($line), explode("\n", $text)));
    }

    /**
     * The text on one line, in the middle of a sentence, shown as the words it is.
     */
    public static function inline(string $text): string
    {
        return self::escape(trim((string) preg_replace('/\s+/', ' ', $text)));
    }

    /**
     * Each character that Markdown reads as something is put after a backslash, which Markdown takes to mean the character itself.
     */
    private static function escape(string $line): string
    {
        return (string) preg_replace('/([\\\\`*_{}\[\]()#+\-.!|<>~&:@=])/', '\\\\$1', $line);
    }
}
