<?php

namespace App\Support\Commerce\GiftCards;

/**
 * How a gift card code is written, matched and stored.
 *
 * A code is 16 characters from an alphabet without the ones that look alike (no 0, O, 1 or I), shown in
 * four groups of four: 80 bits of randomness, so it cannot be guessed and the SHA-256 of it cannot be
 * worked back to it. Customers type codes in any case, with or without the dashes, so every code is
 * looked up in one normalised form.
 */
final class GiftCardCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public const LENGTH = 16;

    /**
     * A new random code, in the form it is shown: XXXX-XXXX-XXXX-XXXX.
     */
    public static function generate(): string
    {
        $characters = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $characters .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return self::format($characters);
    }

    /**
     * What was typed, reduced to the characters that count: upper case, no spaces or dashes.
     */
    public static function normalise(string $input): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '', strtoupper($input));
    }

    /**
     * Whether this (normalised) text could be a code at all, so a lookup is not made for anything else.
     */
    public static function isWellFormed(string $normalised): bool
    {
        return strlen($normalised) === self::LENGTH && strspn($normalised, self::ALPHABET) === self::LENGTH;
    }

    public static function format(string $normalised): string
    {
        return implode('-', str_split($normalised, 4));
    }

    public static function hash(string $normalised): string
    {
        return hash('sha256', $normalised);
    }

    public static function lastFour(string $normalised): string
    {
        return substr($normalised, -4);
    }
}
