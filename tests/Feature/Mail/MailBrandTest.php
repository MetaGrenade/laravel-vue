<?php

namespace Tests\Feature\Mail;

use App\Support\Mail\MailBrand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * What the emails take from the `mail.brand` settings. The values come from an environment file and end up in the HTML and CSS that
 * go out to people, so each is checked to be what it should be.
 */
class MailBrandTest extends TestCase
{
    // --- The colour ----------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_colour_is_the_one_set(): void
    {
        config(['mail.brand.color' => '#0d9488']);

        $this->assertSame('#0d9488', MailBrand::color());
    }

    #[Test]
    public function the_colour_is_the_applications_own_when_none_is_set(): void
    {
        config(['mail.brand.color' => null]);

        $this->assertSame(MailBrand::DEFAULT_COLOR, MailBrand::color());
        $this->assertSame('#4f46e5', MailBrand::DEFAULT_COLOR);
    }

    #[Test]
    public function a_short_colour_is_written_out_and_capitals_and_spaces_are_ignored(): void
    {
        config(['mail.brand.color' => '  #F0A ']);

        $this->assertSame('#ff00aa', MailBrand::color());

        config(['mail.brand.color' => '#0D9488']);

        $this->assertSame('#0d9488', MailBrand::color());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notColours(): array
    {
        return [
            'a name' => ['red'],
            'no hash' => ['0d9488'],
            'two digits' => ['#12'],
            'four digits' => ['#1234'],
            'not hex' => ['#12345g'],
            'seven digits' => ['#1234567'],
            'something after it' => ['#0d9488; } body { display: none'],
            'a function' => ['rgb(0,0,0)'],
            'a url' => ['url(https://example.com/x.png)'],
            'a quote' => ['#0d9488"'],
            'empty' => [''],
            'an array' => [['#0d9488']],
        ];
    }

    #[Test]
    #[DataProvider('notColours')]
    public function anything_that_is_not_a_hex_colour_is_the_default(mixed $value): void
    {
        config(['mail.brand.color' => $value]);

        $this->assertSame(MailBrand::DEFAULT_COLOR, MailBrand::color());
    }

    // --- Readable on it, and on the page -------------------------------------------------------------------------------------

    #[Test]
    public function words_on_a_button_are_white_unless_the_colour_is_too_light_for_that(): void
    {
        config(['mail.brand.color' => '#4f46e5']);
        $this->assertSame('#ffffff', MailBrand::onColor());

        config(['mail.brand.color' => '#000000']);
        $this->assertSame('#ffffff', MailBrand::onColor());

        config(['mail.brand.color' => '#facc15']);
        $this->assertSame('#000000', MailBrand::onColor());

        config(['mail.brand.color' => '#22c55e']);
        $this->assertSame('#000000', MailBrand::onColor(), 'a mid green reads better with black words');
    }

    #[Test]
    public function the_words_on_a_button_can_always_be_read(): void
    {
        foreach (['#4f46e5', '#facc15', '#22c55e', '#0d9488', '#dc2626', '#ffffff', '#000000', '#808080', '#fb923c', '#7c3aed'] as $color) {
            config(['mail.brand.color' => $color]);

            $this->assertGreaterThanOrEqual(4.5, MailBrand::contrast($color, MailBrand::onColor()), $color);
        }
    }

    #[Test]
    public function a_link_is_the_brand_colour_when_that_can_be_read_on_white_and_dark_text_when_it_cannot(): void
    {
        config(['mail.brand.color' => '#4f46e5']);
        $this->assertSame('#4f46e5', MailBrand::linkColor());

        config(['mail.brand.color' => '#facc15']);
        $this->assertSame('#18181b', MailBrand::linkColor());

        foreach (['#4f46e5', '#facc15', '#22c55e', '#0d9488', '#ffffff', '#fb923c'] as $color) {
            config(['mail.brand.color' => $color]);

            $this->assertGreaterThanOrEqual(4.5, MailBrand::contrast(MailBrand::linkColor(), '#ffffff'), $color);
        }
    }

    #[Test]
    public function a_link_in_a_dark_mail_app_is_lightened_until_it_can_be_read_there(): void
    {
        foreach (['#4f46e5', '#000000', '#1e3a8a', '#facc15', '#ffffff', '#dc2626'] as $color) {
            config(['mail.brand.color' => $color]);

            $this->assertGreaterThanOrEqual(4.5, MailBrand::contrast(MailBrand::linkColorOnDark(), MailBrand::DARK_PAGE), $color);
        }

        config(['mail.brand.color' => '#facc15']);
        $this->assertSame('#facc15', MailBrand::linkColorOnDark(), 'a colour that already can be read is not changed');

        config(['mail.brand.color' => '#4f46e5']);
        $this->assertNotSame('#4f46e5', MailBrand::linkColorOnDark(), 'and one that cannot is');
    }

    #[Test]
    public function contrast_is_one_for_the_same_colour_and_twenty_one_for_black_on_white(): void
    {
        $this->assertEqualsWithDelta(1.0, MailBrand::contrast('#4f46e5', '#4f46e5'), 0.0001);
        $this->assertEqualsWithDelta(21.0, MailBrand::contrast('#000000', '#ffffff'), 0.0001);
        $this->assertEqualsWithDelta(MailBrand::contrast('#4f46e5', '#ffffff'), MailBrand::contrast('#ffffff', '#4f46e5'), 0.0001, 'whichever way round');
    }

    // --- The logo ------------------------------------------------------------------------------------------------------------

    #[Test]
    public function the_logo_is_an_address_on_the_web(): void
    {
        config(['mail.brand.logo' => 'https://cdn.example.com/logo.png']);
        $this->assertSame('https://cdn.example.com/logo.png', MailBrand::logo());

        config(['mail.brand.logo' => '  http://acme.test/logo.png?v=2&w=220 ']);
        $this->assertSame('http://acme.test/logo.png?v=2&w=220', MailBrand::logo());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function notLogos(): array
    {
        return [
            'nothing' => [null],
            'empty' => [''],
            'a script' => ['javascript:alert(1)'],
            'inline data' => ['data:image/png;base64,iVBORw0KGgo='],
            'a path' => ['/images/logo.png'],
            'no scheme' => ['//cdn.example.com/logo.png'],
            'a file' => ['file:///etc/passwd'],
            'a quote that ends the attribute' => ['https://cdn.example.com/logo.png" onerror="alert(1)'],
            'a space' => ['https://cdn.example.com/my logo.png'],
            'a tag' => ['https://cdn.example.com/<script>'],
            'a backtick' => ['https://cdn.example.com/`x`'],
            'a line break' => ["https://cdn.example.com/a.png\nBcc: x@example.com"],
        ];
    }

    #[Test]
    #[DataProvider('notLogos')]
    public function anything_else_is_no_logo(mixed $value): void
    {
        config(['mail.brand.logo' => $value]);

        $this->assertNull(MailBrand::logo());
    }

    // --- The name and the footer ---------------------------------------------------------------------------------------------

    #[Test]
    public function the_name_is_the_applications_and_the_badge_shows_its_first_letter(): void
    {
        config(['app.name' => ' Acme Cloud ']);

        $this->assertSame('Acme Cloud', MailBrand::name());
        $this->assertSame('A', MailBrand::initial());

        config(['app.name' => 'élan vital']);
        $this->assertSame('É', MailBrand::initial(), 'a letter, not a byte');

        config(['app.name' => '']);
        $this->assertSame('Laravel', MailBrand::name());
        $this->assertSame('L', MailBrand::initial());
    }

    #[Test]
    public function the_footer_is_one_line_and_there_is_none_when_it_is_empty(): void
    {
        config(['mail.brand.footer' => "Acme Cloud Ltd\n  1 High Street,   London "]);
        $this->assertSame('Acme Cloud Ltd 1 High Street, London', MailBrand::footer());

        config(['mail.brand.footer' => '   ']);
        $this->assertNull(MailBrand::footer());

        config(['mail.brand.footer' => null]);
        $this->assertNull(MailBrand::footer());
    }
}
