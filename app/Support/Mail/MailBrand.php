<?php

namespace App\Support\Mail;

/**
 * How the emails look (docs/email.md): what the layout and theme in resources/views take from the `mail.brand` settings.
 *
 * Everything here ends up in HTML or CSS that goes out to people, and it comes from an environment file, so each value is checked
 * to be what it should be and falls back to a safe one when it is not.
 */
final class MailBrand
{
    /** The application's own colour (the `--primary` of the site). */
    public const DEFAULT_COLOR = '#4f46e5';

    /** The text on a light page, and the page it is on. */
    private const DARK = '#18181b';

    private const LIGHT = '#ffffff';

    /**
     * The words on a light button are black and not the dark of the text: of white and black, one reads at 4.5 to 1 or better on any
     * colour at all, and that is not true of anything short of black.
     */
    private const BLACK = '#000000';

    /** The page of the email in a dark mail app (vendor/mail/html/layout.blade.php). */
    public const DARK_PAGE = '#27272a';

    public static function name(): string
    {
        return trim((string) config('app.name')) ?: 'Laravel';
    }

    public static function url(): string
    {
        return (string) config('app.url');
    }

    /**
     * The colour of buttons, as `#rrggbb`. Anything that is not a hex colour (three or six digits) is the default, so a typo in the
     * environment cannot put something else in the stylesheet.
     */
    public static function color(): string
    {
        $color = strtolower(self::setting('color'));

        if (preg_match('/^#([0-9a-f]{3})$/', $color, $short) === 1) {
            return '#'.$short[1][0].$short[1][0].$short[1][1].$short[1][1].$short[1][2].$short[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $color) === 1 ? $color : self::DEFAULT_COLOR;
    }

    /**
     * The colour of the words on a button: white, unless the brand colour is light enough for black to be read better on it.
     */
    public static function onColor(): string
    {
        return self::contrast(self::color(), self::LIGHT) >= self::contrast(self::color(), self::BLACK) ? self::LIGHT : self::BLACK;
    }

    /**
     * The colour of links in the text, which sit on white: the brand colour, or dark text when the brand colour is too light to read
     * there (WCAG's 4.5 to 1).
     */
    public static function linkColor(): string
    {
        return self::contrast(self::color(), self::LIGHT) >= 4.5 ? self::color() : self::DARK;
    }

    /**
     * The colour of links for a reader whose mail app is dark (the page behind the text is {@see self::DARK_PAGE}): the brand colour
     * lightened until it can be read there.
     */
    public static function linkColorOnDark(): string
    {
        for ($step = 0; $step <= 10; $step++) {
            $color = self::mix(self::color(), self::LIGHT, $step / 10);

            if (self::contrast($color, self::DARK_PAGE) >= 4.5) {
                return $color;
            }
        }

        return self::LIGHT;
    }

    /**
     * The address of the logo, if it is an image somewhere on the web. Not a `data:` or `javascript:` address, and not an address
     * with something after it that would end the attribute it sits in.
     */
    public static function logo(): ?string
    {
        $logo = self::setting('logo');

        if ($logo === '' || preg_match('#^https?://[^\s"\'<>`]+$#i', $logo) !== 1) {
            return null;
        }

        return $logo;
    }

    /**
     * The first letter of the name, for the badge that stands in for a logo.
     */
    public static function initial(): string
    {
        return mb_strtoupper(mb_substr(self::name(), 0, 1));
    }

    /**
     * A line to put under every email, such as a company name and address (none if the setting is empty).
     */
    public static function footer(): ?string
    {
        $footer = trim((string) preg_replace('/\s+/', ' ', self::setting('footer')));

        return $footer === '' ? null : $footer;
    }

    /**
     * A `mail.brand` setting as text (nothing for one that is not text, so a mistake in the configuration is not an error in an email).
     */
    private static function setting(string $key): string
    {
        $value = config('mail.brand.'.$key);

        return is_string($value) ? trim($value) : '';
    }

    /**
     * WCAG's contrast ratio of two `#rrggbb` colours, from 1 (the same) to 21 (black on white).
     */
    public static function contrast(string $a, string $b): float
    {
        [$lighter, $darker] = [max(self::luminance($a), self::luminance($b)), min(self::luminance($a), self::luminance($b))];

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * A colour part of the way from one `#rrggbb` to another (0 is the first, 1 the second).
     */
    private static function mix(string $from, string $to, float $amount): string
    {
        $from = array_map('hexdec', str_split(ltrim($from, '#'), 2));
        $to = array_map('hexdec', str_split(ltrim($to, '#'), 2));

        return '#'.implode('', array_map(
            fn (int $a, int $b) => str_pad(dechex((int) round($a + ($b - $a) * $amount)), 2, '0', STR_PAD_LEFT),
            $from,
            $to,
        ));
    }

    private static function luminance(string $color): float
    {
        [$red, $green, $blue] = array_map(function (string $channel): float {
            $value = hexdec($channel) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($color, '#'), 2));

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }
}
