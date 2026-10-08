<?php

namespace App\Support\Commerce;

use Locale;

/**
 * The countries an address can be in (ISO 3166-1 alpha-2). The browser shows
 * their names with Intl.DisplayNames; the server only needs the codes, to
 * validate addresses and to match shipping zones and tax rates.
 */
final class Countries
{
    /**
     * Matches every country in a shipping zone or tax rate (a "rest of world" entry).
     */
    public const ANY = '*';

    /**
     * @var list<string>
     */
    private const CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR', 'AS', 'AT', 'AU', 'AW', 'AX', 'AZ',
        'BA', 'BB', 'BD', 'BE', 'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ', 'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ',
        'CA', 'CC', 'CD', 'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR', 'CU', 'CV', 'CW', 'CX', 'CY', 'CZ',
        'DE', 'DJ', 'DK', 'DM', 'DO', 'DZ',
        'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET',
        'FI', 'FJ', 'FK', 'FM', 'FO', 'FR',
        'GA', 'GB', 'GD', 'GE', 'GF', 'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS', 'GT', 'GU', 'GW', 'GY',
        'HK', 'HM', 'HN', 'HR', 'HT', 'HU',
        'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT',
        'JE', 'JM', 'JO', 'JP',
        'KE', 'KG', 'KH', 'KI', 'KM', 'KN', 'KP', 'KR', 'KW', 'KY', 'KZ',
        'LA', 'LB', 'LC', 'LI', 'LK', 'LR', 'LS', 'LT', 'LU', 'LV', 'LY',
        'MA', 'MC', 'MD', 'ME', 'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ', 'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ',
        'NA', 'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU', 'NZ',
        'OM',
        'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM', 'PN', 'PR', 'PS', 'PT', 'PW', 'PY',
        'QA',
        'RE', 'RO', 'RS', 'RU', 'RW',
        'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI', 'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV', 'SX', 'SY', 'SZ',
        'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK', 'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ',
        'UA', 'UG', 'UM', 'US', 'UY', 'UZ',
        'VA', 'VC', 'VE', 'VG', 'VI', 'VN', 'VU',
        'WF', 'WS',
        'YE', 'YT',
        'ZA', 'ZM', 'ZW',
    ];

    /**
     * Countries that do not use postal codes, so asking for one would block a
     * genuine customer. Everywhere else a postal code is required.
     *
     * @var list<string>
     */
    private const WITHOUT_POSTAL_CODES = [
        'AE', 'AG', 'AO', 'AW', 'BF', 'BI', 'BJ', 'BS', 'BW', 'BZ', 'CD', 'CF', 'CG', 'CI', 'CK', 'CM',
        'DJ', 'DM', 'ER', 'FJ', 'GD', 'GH', 'GM', 'GQ', 'GY', 'HK', 'KI', 'KM', 'KN', 'KP', 'LY', 'ML',
        'MO', 'MR', 'MW', 'NR', 'NU', 'PA', 'QA', 'RW', 'SB', 'SC', 'SL', 'SO', 'SR', 'ST', 'SY', 'TF',
        'TG', 'TK', 'TL', 'TO', 'TV', 'UG', 'VU', 'YE', 'ZW',
    ];

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return self::CODES;
    }

    public static function isValid(string $code): bool
    {
        return in_array(strtoupper($code), self::CODES, true);
    }

    public static function usesPostalCodes(string $code): bool
    {
        return ! in_array(strtoupper($code), self::WITHOUT_POSTAL_CODES, true);
    }

    /**
     * The English name of a country, falling back to its code when the intl
     * extension is not installed.
     */
    public static function name(string $code): string
    {
        $code = strtoupper($code);

        if ($code === self::ANY) {
            return 'Rest of world';
        }

        if (class_exists(Locale::class)) {
            $name = Locale::getDisplayRegion('-'.$code, 'en');

            if ($name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }
}
