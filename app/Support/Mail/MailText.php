<?php

namespace App\Support\Mail;

use Illuminate\Support\Facades\Lang;

/**
 * Text somebody typed, put in an email (docs/email.md). The lines of a notification are read as Markdown, so a `[link](https://...)`
 * or a `*` in what a moderator wrote would become a link or emphasis in the email.
 *
 * And the few words that every notification needs in the same form, in the language of the person it is sent to (docs/i18n.md).
 */
final class MailText
{
    /**
     * "Hi Avery!", or "Hi there!" for somebody with no name to say.
     */
    public static function greeting(object $notifiable): string
    {
        $name = $notifiable->nickname ?? $notifiable->name ?? null;

        return $name === null ? __('mail.greeting_anonymous') : __('mail.greeting', ['name' => $name]);
    }

    /**
     * The name of a stored value (a ticket's status, how often a plan bills) as the language files have it, `<group>.<value>`; the
     * fallback, or else the value as it is stored, when they have none: a status that was added later is still said, in English.
     */
    public static function label(string $group, string $value, ?string $fallback = null): string
    {
        return Lang::has("{$group}.{$value}") ? __("{$group}.{$value}") : ($fallback ?? $value);
    }

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
