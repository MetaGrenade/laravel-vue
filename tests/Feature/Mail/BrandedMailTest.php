<?php

namespace Tests\Feature\Mail;

use App\Models\User;
use App\Notifications\ApiKeyCreated;
use App\Notifications\SubscriptionCancelledForNonPayment;
use App\Notifications\SubscriptionTrialEnding;
use App\Support\Mail\MailBrand;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Every email the site sends is in one layout: the name and colour of the site, a logo or a badge, a footer, a plain-text version and a
 * dark one (docs/email.md). The notifications say what to say; the layout is not theirs to choose.
 */
class BrandedMailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.name' => 'Acme Cloud',
            'app.url' => 'https://acme.test',
            'mail.brand.color' => '#0d9488',
            'mail.brand.logo' => null,
            'mail.brand.footer' => null,
        ]);
    }

    private function html(MailMessage $message): string
    {
        return (string) $message->render();
    }

    /**
     * How many of an element the page has, as a browser reads it. The renderer puts a `style` on every element, so a search for the text
     * `<b>` finds none whether the page has one or not.
     */
    private function elements(string $html, string $tag): int
    {
        libxml_use_internal_errors(true);
        $page = new \DOMDocument;
        $page->loadHTML($html);

        return $page->getElementsByTagName($tag)->length;
    }

    private function text(MailMessage $message): string
    {
        return (string) app(Markdown::class)->renderText($message->markdown, $message->data());
    }

    private function message(): MailMessage
    {
        return (new MailMessage)
            ->greeting('Hi Ada!')
            ->line('Something happened.')
            ->action('Look at it', 'https://acme.test/look');
    }

    // --- The layout ----------------------------------------------------------------------------------------------------------

    #[Test]
    public function an_email_has_the_name_of_the_site_and_a_badge_in_its_header(): void
    {
        $html = $this->html($this->message());

        $this->assertStringContainsString('<title>Acme Cloud</title>', $html);
        $this->assertMatchesRegularExpression('#<td class="mark"[^>]*><a href="https://acme.test"[^>]*>A</a></td>#', $html, 'the badge holds the first letter of the name, and links to the site');
        $this->assertMatchesRegularExpression('#<td class="wordmark"[^>]*><a href="https://acme.test"[^>]*>Acme Cloud</a></td>#', $html);
        $this->assertStringNotContainsString('laravel.com/img', $html);
    }

    #[Test]
    public function the_button_and_the_badge_are_the_brand_colour(): void
    {
        $html = $this->html($this->message());

        $this->assertMatchesRegularExpression('#class="button button-primary"[^>]*style="[^"]*background-color: \#0d9488#', $html);
        $this->assertMatchesRegularExpression('#class="mark"[^>]*style="[^"]*background-color: \#0d9488#', $html);
        // The button is its borders as much as its background (that is how it gets its size in mail apps).
        foreach (['top', 'right', 'bottom', 'left'] as $side) {
            $this->assertMatchesRegularExpression('#class="button button-primary"[^>]*style="[^"]*border-'.$side.': \d+px solid \#0d9488#', $html, $side);
        }

        $this->assertStringNotContainsString('#18181b; border-bottom: 8px', $html, 'not the colour of the layout it replaced');
    }

    #[Test]
    public function a_different_colour_in_the_settings_is_a_different_colour_in_the_email(): void
    {
        config(['mail.brand.color' => '#dc2626']);
        $html = $this->html($this->message());

        $this->assertMatchesRegularExpression('#class="button button-primary"[^>]*style="[^"]*background-color: \#dc2626#', $html);
        $this->assertStringNotContainsString('#0d9488', $html);
    }

    #[Test]
    public function without_a_colour_in_the_settings_the_email_is_the_sites_own(): void
    {
        config(['mail.brand.color' => null]);

        $this->assertMatchesRegularExpression('#class="button button-primary"[^>]*style="[^"]*background-color: \#4f46e5#', $this->html($this->message()));
    }

    #[Test]
    public function an_error_or_success_notice_keeps_its_own_button_colour(): void
    {
        $this->assertMatchesRegularExpression(
            '#class="button button-error"[^>]*style="[^"]*background-color: \#dc2626#',
            $this->html($this->message()->level('error')),
        );
        $this->assertMatchesRegularExpression(
            '#class="button button-success"[^>]*style="[^"]*background-color: \#16a34a#',
            $this->html($this->message()->level('success')),
        );
    }

    #[Test]
    public function words_on_a_light_button_are_dark_and_on_a_dark_one_white(): void
    {
        config(['mail.brand.color' => '#facc15']);
        $html = $this->html($this->message());
        $this->assertMatchesRegularExpression('#class="button button-primary"[^>]*style="[^"]*color: \#000000#', $html);
        $this->assertMatchesRegularExpression('#<td class="mark"[^>]*><a [^>]*style="[^"]*color: \#000000#', $html, 'and so is the letter in the badge');

        config(['mail.brand.color' => '#4f46e5']);
        $html = $this->html($this->message());
        $this->assertMatchesRegularExpression('#class="button button-primary"[^>]*style="[^"]*color: \#ffffff#', $html);
        $this->assertMatchesRegularExpression('#<td class="mark"[^>]*><a [^>]*style="[^"]*color: \#ffffff#', $html);
    }

    #[Test]
    public function a_link_in_the_text_is_the_brand_colour_when_it_can_be_read_and_dark_when_it_cannot(): void
    {
        $link = function (): string {
            preg_match_all('#<a href="https://acme.test/look"([^>]*)>#', $this->html($this->message()), $found);

            $plain = array_values(array_filter($found[1], fn (string $attributes) => ! str_contains($attributes, 'class="button')));
            $this->assertNotEmpty($plain, 'the link under the button');

            return $plain[0];
        };

        config(['mail.brand.color' => '#4f46e5']);
        $this->assertStringContainsString('color: #4f46e5;', $link());

        config(['mail.brand.color' => '#facc15']);
        $this->assertStringContainsString('color: #18181b;', $link());
        $this->assertStringNotContainsString('color: #facc15;', $link());
    }

    #[Test]
    public function a_logo_takes_the_place_of_the_badge(): void
    {
        config(['mail.brand.logo' => 'https://cdn.acme.test/logo.png']);
        $html = $this->html($this->message());

        $this->assertStringContainsString('<img src="https://cdn.acme.test/logo.png" class="logo" alt="Acme Cloud"', $html);
        $this->assertStringNotContainsString('class="mark"', $html);
    }

    #[Test]
    public function an_address_that_is_not_an_image_on_the_web_is_not_used_as_one(): void
    {
        config(['mail.brand.logo' => 'javascript:alert(1)']);
        $html = $this->html($this->message());

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('class="mark"', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    #[Test]
    public function a_footer_line_goes_under_every_email_in_both_versions(): void
    {
        config(['mail.brand.footer' => 'Acme Cloud Ltd, 1 High Street, London']);
        $message = $this->message();

        $this->assertStringContainsString('Acme Cloud Ltd, 1 High Street, London', $this->html($message));
        $this->assertStringContainsString('© '.date('Y').' Acme Cloud.', $this->html($message));
        $this->assertStringContainsString('Acme Cloud Ltd, 1 High Street, London', $this->text($message));
        $this->assertStringContainsString('© '.date('Y').' Acme Cloud.', $this->text($message));
    }

    #[Test]
    public function without_a_footer_line_there_is_only_the_copyright(): void
    {
        $html = $this->html($this->message());

        $this->assertStringContainsString('© '.date('Y').' Acme Cloud. All rights reserved.', $html);
        $this->assertDoesNotMatchRegularExpression('#class="footer".*<p>[^<©]+</p>.*<p>©#s', $html);
    }

    #[Test]
    public function the_plain_text_version_has_the_name_and_none_of_the_defaults(): void
    {
        $text = $this->text($this->message());

        $this->assertStringContainsString('Acme Cloud: https://acme.test', $text);
        $this->assertStringContainsString('Hi Ada!', $text);
        $this->assertStringContainsString('Look at it: https://acme.test/look', $text);
        $this->assertStringNotContainsString('Laravel', $text);
        $this->assertStringNotContainsString('<', $text, 'no markup in plain text');
    }

    #[Test]
    public function the_page_has_styles_for_a_dark_mail_app_and_says_it_can_be_read_in_either(): void
    {
        config(['mail.brand.color' => '#4f46e5']);
        $html = $this->html($this->message());

        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $html);
        $this->assertMatchesRegularExpression('#\.inner-body a \{\s*color: '.preg_quote(MailBrand::linkColorOnDark(), '#').' !important;#', $html);
        $this->assertNotSame('#4f46e5', MailBrand::linkColorOnDark(), 'lightened, as the brand colour cannot be read on a dark page');
        $this->assertMatchesRegularExpression('#\.inner-body a\.button, \.mark, \.header \.mark a \{\s*color: \#ffffff !important;#', $html, 'the words on a button do not take the colour of a link');
    }

    #[Test]
    public function the_pages_language_is_the_apps(): void
    {
        app()->setLocale('en');

        $this->assertStringContainsString('lang="en"', $this->html($this->message()));
    }

    // --- What is put in it ---------------------------------------------------------------------------------------------------

    #[Test]
    public function a_name_with_markup_in_it_is_shown_as_text(): void
    {
        config(['app.name' => '<b>Acme</b> & "Co"']);
        $html = $this->html($this->message());

        $this->assertSame(0, $this->elements($html, 'b'), 'no element came of the name');
        $this->assertStringContainsString('&lt;b&gt;Acme&lt;/b&gt; &amp; "Co"', $html);
        $this->assertMatchesRegularExpression('#<td class="mark"[^>]*><a [^>]*>&lt;</a></td>#', $html, 'the badge is a letter, not a tag');
    }

    #[Test]
    public function a_name_with_a_quote_in_it_cannot_end_the_attribute_it_is_in(): void
    {
        config(['app.name' => 'Acme "Cloud" onload="x', 'mail.brand.logo' => 'https://cdn.acme.test/logo.png']);

        // Read as a browser reads it: the name is the whole of the `alt`, and nothing it holds became an attribute of its own.
        libxml_use_internal_errors(true);
        $page = new \DOMDocument;
        $page->loadHTML($this->html($this->message()));
        $logo = $page->getElementsByTagName('img')->item(0);

        $this->assertSame('Acme "Cloud" onload="x', $logo->getAttribute('alt'));
        $this->assertFalse($logo->hasAttribute('onload'));
        $this->assertSame('Acme "Cloud" onload="x', $page->getElementsByTagName('title')->item(0)->textContent);
    }

    #[Test]
    public function a_colour_that_is_not_one_cannot_get_into_the_styles(): void
    {
        config(['mail.brand.color' => '#0d9488; } body { display: none']);
        $html = $this->html($this->message());

        $this->assertStringNotContainsString('display: none', $html);
        $this->assertMatchesRegularExpression('#class="button button-primary"[^>]*style="[^"]*background-color: \#4f46e5#', $html);
    }

    #[Test]
    public function a_footer_with_markup_in_it_is_shown_as_text(): void
    {
        config(['mail.brand.footer' => '<script>alert(1)</script> Acme']);

        $html = $this->html($this->message());

        $this->assertSame(0, $this->elements($html, 'script'), 'no element came of the footer');
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; Acme', $html);
    }

    // --- The notifications ---------------------------------------------------------------------------------------------------

    #[Test]
    public function the_emails_the_site_writes_are_in_the_layout(): void
    {
        $user = User::factory()->create(['nickname' => 'Ada']);

        $messages = [
            'an API key was made' => (new ApiKeyCreated('CI', ApiKeyCreated::SETTINGS, ['Read your profile'], false, null, '203.0.113.9'))->toMail($user),
            'a subscription was cancelled' => (new SubscriptionCancelledForNonPayment('Pro'))->toMail($user),
            'a trial is ending' => (new SubscriptionTrialEnding('Pro', now()->addDays(3), 1200, 'usd', 'month'))->toMail($user),
        ];

        foreach ($messages as $what => $message) {
            $html = $this->html($message);

            $this->assertStringContainsString('class="wordmark"', $html, $what);
            $this->assertMatchesRegularExpression('#class="button button-primary"[^>]*style="[^"]*background-color: \#0d9488#', $html, $what);
            $this->assertStringContainsString('Acme Cloud. All rights reserved.', $html, $what);
            $this->assertStringNotContainsString('laravel.com/img', $html, $what);
            $this->assertStringContainsString('Acme Cloud: https://acme.test', $this->text($message), $what);
        }
    }

    #[Test]
    public function the_emails_laravel_sends_itself_are_in_the_layout_too(): void
    {
        $user = User::factory()->create();

        foreach ([(new VerifyEmail)->toMail($user), (new ResetPassword('a-token'))->toMail($user)] as $message) {
            $html = $this->html($message);

            $this->assertStringContainsString('class="wordmark"', $html);
            $this->assertMatchesRegularExpression('#class="button button-primary"[^>]*style="[^"]*background-color: \#0d9488#', $html);
            $this->assertStringNotContainsString('laravel.com/img', $html);
        }
    }

    #[Test]
    public function every_email_is_sent_through_the_theme_and_components_of_the_application(): void
    {
        $this->assertSame('brand', config('mail.markdown.theme'));
        $this->assertContains(resource_path('views/vendor/mail'), config('mail.markdown.paths'));
        $this->assertTrue(view()->exists('mail.brand'));
        $this->assertFileExists(resource_path('views/vendor/mail/html/layout.blade.php'));
        $this->assertFileExists(resource_path('views/vendor/mail/html/header.blade.php'));
        $this->assertFileExists(resource_path('views/vendor/mail/html/message.blade.php'));
        $this->assertFileExists(resource_path('views/vendor/mail/text/message.blade.php'));
    }

    #[Test]
    public function no_notification_chooses_a_layout_of_its_own(): void
    {
        $checked = 0;

        foreach ((new Finder)->files()->in(base_path('app/Notifications'))->name('*.php') as $file) {
            $source = $file->getContents();

            if (! str_contains($source, 'MailMessage')) {
                continue;
            }

            $checked++;

            $this->assertDoesNotMatchRegularExpression('/->(view|markdown|theme)\(/', $source, $file->getFilename().' picks its own template');
        }

        $this->assertGreaterThan(10, $checked, 'this did not look at the notifications');
        $this->assertDirectoryDoesNotExist(base_path('app/Mail'), 'a Mailable has to be put through the layout and checked here');
    }
}
