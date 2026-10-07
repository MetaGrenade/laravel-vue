<?php

namespace Tests\Unit\Support;

use App\Support\Commerce\Countries;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class CountriesTest extends TestCase
{
    #[Test]
    public function it_lists_every_iso_country_once(): void
    {
        $codes = Countries::codes();

        $this->assertCount(249, $codes);
        $this->assertSame($codes, array_values(array_unique($codes)), 'no duplicates');

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $code);
        }
    }

    #[Test]
    public function it_knows_the_countries_shops_actually_sell_to(): void
    {
        foreach (['US', 'GB', 'DE', 'FR', 'CA', 'AU', 'JP', 'IN', 'BR', 'ZA', 'NZ', 'IE', 'NL', 'ES', 'IT', 'SE', 'NO', 'CH', 'MX', 'SG'] as $code) {
            $this->assertTrue(Countries::isValid($code), $code);
        }
    }

    #[Test]
    public function it_validates_codes_case_insensitively_and_rejects_nonsense(): void
    {
        $this->assertTrue(Countries::isValid('gb'));
        $this->assertFalse(Countries::isValid('ZZ'));
        $this->assertFalse(Countries::isValid('*'), 'the rest-of-world marker is not a country');
        $this->assertFalse(Countries::isValid(''));
        $this->assertFalse(Countries::isValid('GBR'));
    }

    #[Test]
    public function postal_codes_are_expected_except_where_a_country_has_none(): void
    {
        $this->assertTrue(Countries::usesPostalCodes('GB'));
        $this->assertTrue(Countries::usesPostalCodes('us'));
        $this->assertFalse(Countries::usesPostalCodes('HK'));
        $this->assertFalse(Countries::usesPostalCodes('ae'));
    }

    #[Test]
    public function the_countries_without_postal_codes_are_all_real_countries(): void
    {
        $withoutCodes = (new \ReflectionClassConstant(Countries::class, 'WITHOUT_POSTAL_CODES'))->getValue();

        $this->assertSame([], array_diff($withoutCodes, Countries::codes()));
    }

    #[Test]
    public function it_names_countries_in_english(): void
    {
        if (! class_exists(\Locale::class)) {
            $this->markTestSkipped('The intl extension is not installed; names fall back to codes.');
        }

        $this->assertSame('Germany', Countries::name('DE'));
        $this->assertSame('United States', Countries::name('us'));
        $this->assertSame('Rest of world', Countries::name(Countries::ANY));
    }
}
