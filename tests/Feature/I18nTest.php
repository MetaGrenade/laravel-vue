<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Support\Localization\FrontendTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class I18nTest extends TestCase
{
    use RefreshDatabase;

    private function sharedProps(?User $user = null, array $headers = [], array $cookies = []): array
    {
        $request = $this->withHeaders(array_merge([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        ], $headers));

        if ($user) {
            $request = $request->actingAs($user);
        }

        foreach ($cookies as $name => $value) {
            $request = $request->withCookie($name, $value);
        }

        return $request->get(route('home'))
            ->assertOk()
            ->json('props');
    }

    #[Test]
    public function the_front_end_receives_the_locale_and_translations(): void
    {
        $props = $this->sharedProps();

        $this->assertSame('en', $props['locale']);
        $this->assertSame(['en'], $props['locales']);
        $this->assertSame('Home', $props['translations']['ui.nav.home']);
        $this->assertSame('Change colour theme', $props['translations']['ui.theme.change']);
    }

    #[Test]
    public function unsupported_languages_are_ignored_in_favour_of_the_default(): void
    {
        config(['i18n.supported' => ['en']]);

        $user = User::factory()->create(['locale' => 'fr_FR']);

        $props = $this->sharedProps($user, ['Accept-Language' => 'de-DE,de;q=0.9'], ['locale' => 'es']);

        $this->assertSame('en', $props['locale']);
    }

    #[Test]
    public function the_users_preference_wins_over_the_cookie_and_the_browser(): void
    {
        config(['i18n.supported' => ['en', 'fr', 'de']]);

        $user = User::factory()->create(['locale' => 'fr_CA']);

        $props = $this->sharedProps($user, ['Accept-Language' => 'de'], ['locale' => 'de']);

        $this->assertSame('fr', $props['locale']);
    }

    #[Test]
    public function the_cookie_wins_over_the_browser_for_guests(): void
    {
        config(['i18n.supported' => ['en', 'fr', 'de']]);

        $props = $this->sharedProps(null, ['Accept-Language' => 'de'], ['locale' => 'fr']);

        $this->assertSame('fr', $props['locale']);
    }

    #[Test]
    public function the_browser_language_is_used_when_nothing_else_is_set(): void
    {
        config(['i18n.supported' => ['en', 'de']]);

        $props = $this->sharedProps(null, ['Accept-Language' => 'de-DE,de;q=0.9,en;q=0.5']);

        $this->assertSame('de', $props['locale']);
    }

    #[Test]
    public function the_html_language_attribute_follows_the_locale(): void
    {
        $this->get(route('home'))->assertSee('<html lang="en"', false);
    }

    #[Test]
    public function translations_fall_back_to_the_default_language_for_missing_keys(): void
    {
        $directory = lang_path('xx');
        File::ensureDirectoryExists($directory);
        File::put("{$directory}/ui.php", "<?php return ['nav' => ['home' => 'Startseite']];");

        try {
            $strings = FrontendTranslations::forGroups(['ui'], 'xx');

            $this->assertSame('Startseite', $strings['ui.nav.home']);
            $this->assertSame('Pricing', $strings['ui.nav.pricing']);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    #[Test]
    public function lang_check_passes_when_every_language_has_every_key(): void
    {
        $this->artisan('lang:check')->assertSuccessful();
    }

    #[Test]
    public function lang_check_fails_and_names_the_missing_keys(): void
    {
        $path = storage_path('framework/testing/lang-'.Str::random(8));
        File::ensureDirectoryExists("{$path}/en");
        File::ensureDirectoryExists("{$path}/fr");
        File::put("{$path}/en/ui.php", "<?php return ['a' => 'A', 'nested' => ['b' => 'B']];");
        File::put("{$path}/fr/ui.php", "<?php return ['a' => 'A'];");

        try {
            $this->artisan('lang:check', ['--path' => $path])
                ->expectsOutputToContain('ui.nested.b')
                ->assertFailed();
        } finally {
            File::deleteDirectory($path);
        }
    }
}
