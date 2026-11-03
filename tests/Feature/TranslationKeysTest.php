<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ShareTranslationGroups;
use App\Models\User;
use App\Support\Localization\FrontendTranslations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The words on a page are language keys (docs/i18n.md). A key that is missing from the language file shows up as the key itself, which
 * no other test would notice, and a group that the page is not sent shows up the same way. These tests read the front end's source for
 * the keys it uses and check that each one exists and reaches the page that shows it.
 */
class TranslationKeysTest extends TestCase
{
    use RefreshDatabase;

    /**
     * What a page is sent: the component it renders and the strings that arrive with it.
     *
     * @return array{component: string, translations: array<string, string>}
     */
    private function page(string $url, ?User $user = null, array $session = []): array
    {
        $request = $this->withSession($session)->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(Request::create('/')),
        ]);

        if ($user) {
            $request = $request->actingAs($user);
        }

        $json = $request->get($url)->assertOk()->json();

        return ['component' => $json['component'], 'translations' => $json['props']['translations']];
    }

    /**
     * Every key of a group written out as a string in a file: `t('auth.login.title')`, and a choice between two of them.
     *
     * @return list<string>
     */
    private function keysIn(string $file, string $group): array
    {
        preg_match_all("/'({$group}\\.[a-z0-9_.]+)'/", File::get(resource_path($file)), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function authPages(): array
    {
        return [
            'log in' => ['/login', 'auth/Login'],
            'register' => ['/register', 'auth/Register'],
            'forgot password' => ['/forgot-password', 'auth/ForgotPassword'],
            'reset password' => ['/reset-password/token?email=member%40example.com', 'auth/ResetPassword'],
            'two-factor challenge' => ['/two-factor-challenge', 'auth/TwoFactorChallenge'],
            'verify email' => ['/verify-email', 'auth/VerifyEmail'],
            'confirm password' => ['/confirm-password', 'auth/ConfirmPassword'],
        ];
    }

    #[Test]
    #[DataProvider('authPages')]
    public function an_auth_page_is_sent_every_string_it_and_its_layout_show(string $url, string $component): void
    {
        $user = match ($component) {
            'auth/VerifyEmail' => User::factory()->unverified()->create(),
            'auth/ConfirmPassword' => User::factory()->create(),
            default => null,
        };

        $session = $component === 'auth/TwoFactorChallenge' ? ['two_factor:id' => 1] : [];

        $page = $this->page($url, $user, $session);

        $this->assertSame($component, $page['component']);

        $keys = array_merge(
            $this->keysIn("js/pages/{$component}.vue", 'auth'),
            $this->keysIn('js/layouts/auth/AuthSimpleLayout.vue', 'auth'),
        );

        $this->assertNotEmpty($keys, "{$component} uses no auth keys: has it been converted?");

        foreach ($keys as $key) {
            $this->assertArrayHasKey($key, $page['translations'], "{$component} shows {$key}, which was not sent with it.");
        }
    }

    #[Test]
    public function the_pages_that_are_not_auth_pages_are_not_made_heavier_by_the_auth_strings(): void
    {
        $page = $this->page('/');

        $this->assertSame([], array_filter(array_keys($page['translations']), fn (string $key) => str_starts_with($key, 'auth.')));
        $this->assertArrayHasKey('ui.nav.home', $page['translations']);
    }

    #[Test]
    public function a_route_adds_groups_to_the_ones_every_page_gets(): void
    {
        $request = Request::create('/');

        $this->assertSame([], ShareTranslationGroups::groups($request));

        $middleware = new ShareTranslationGroups;
        $middleware->handle($request, fn () => response('ok'), 'auth', 'shop');
        $middleware->handle($request, fn () => response('ok'), 'auth', 'billing');

        $this->assertSame(['auth', 'shop', 'billing'], ShareTranslationGroups::groups($request));

        // Another request has none of them: nothing is kept anywhere but on the request.
        $this->assertSame([], ShareTranslationGroups::groups(Request::create('/')));
    }

    #[Test]
    public function a_translation_key_the_front_end_uses_is_in_the_language_files(): void
    {
        $known = [];

        foreach (File::files(lang_path('en')) as $file) {
            $known = array_merge($known, FrontendTranslations::forGroups([$file->getFilenameWithoutExtension()], 'en'));
        }

        $missing = [];

        foreach (File::allFiles(resource_path('js')) as $file) {
            // The composable's own documentation names keys that are only examples.
            if (! in_array($file->getExtension(), ['vue', 'ts'], true) || in_array($file->getFilename(), ['useI18n.ts', 'i18n.ts'], true)) {
                continue;
            }

            // A call with a key written out: t('ui.nav.home') or tc('shop.items', 3). A key built from a variable is not found here.
            preg_match_all("/\\b(?:t|tc)\\(\\s*'([a-z_]+\\.[a-z0-9_.]+)'/", $file->getContents(), $matches);

            foreach ($matches[1] as $key) {
                if (! array_key_exists($key, $known)) {
                    $missing[] = "{$key} ({$file->getRelativePathname()})";
                }
            }
        }

        $this->assertSame([], $missing, "Keys that are not in lang/en:\n".implode("\n", $missing));
    }

    #[Test]
    public function a_group_the_front_end_reads_is_sent_by_every_page_or_by_a_route(): void
    {
        $sent = (array) config('i18n.shared_groups');

        foreach (Route::getRoutes() as $route) {
            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'translations:')) {
                    $sent = array_merge($sent, explode(',', substr($middleware, strlen('translations:'))));
                }
            }
        }

        $unsent = [];

        foreach (File::allFiles(resource_path('js')) as $file) {
            if (! in_array($file->getExtension(), ['vue', 'ts'], true) || in_array($file->getFilename(), ['useI18n.ts', 'i18n.ts'], true)) {
                continue;
            }

            preg_match_all("/\\b(?:t|tc)\\(\\s*'([a-z_]+)\\.[a-z0-9_.]+'/", $file->getContents(), $matches);

            foreach (array_unique($matches[1]) as $group) {
                if (! in_array($group, $sent, true)) {
                    $unsent[] = "{$group} ({$file->getRelativePathname()})";
                }
            }
        }

        $this->assertSame([], $unsent, "Groups that no page is sent:\n".implode("\n", $unsent));
    }
}
