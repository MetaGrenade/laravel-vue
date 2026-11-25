<?php

namespace Tests\Feature;

use App\Models\User;
use DOMDocument;
use DOMElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Vite;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The API reference at /api/docs is Swagger UI, built with the site (resources/js/api-docs.ts). It used to come from another host, started
 * by a script written into the page, and the content security policy refuses both: the page was blank. Here is what the policy needs of
 * the page; that it draws, and what Try it out sends, is in the browser suite (tests/e2e/smoke.spec.ts).
 */
class ApiDocsPageTest extends TestCase
{
    use RefreshDatabase;

    private const ENTRY = 'resources/js/api-docs.ts';

    protected function setUp(): void
    {
        parent::setUp();

        // The built files, not a dev server's addresses (see PrefetchAssetsTest).
        Vite::useHotFile(storage_path('framework/testing/vite-no-hot-file'));
    }

    #[Test]
    public function a_visitor_is_sent_nothing_the_policy_refuses(): void
    {
        $this->assertThePageSuitsItsPolicy($this->get(route('api.docs')));
    }

    #[Test]
    public function a_signed_in_member_is_sent_nothing_the_policy_refuses(): void
    {
        // A member is also sent the script that fetches files ahead of time, and the middleware that asks for it.
        $this->assertThePageSuitsItsPolicy($this->actingAs(User::factory()->create())->get(route('api.docs')));
    }

    #[Test]
    public function the_page_has_the_policy_every_page_has(): void
    {
        $withoutNonce = fn (TestResponse $response): string => (string) preg_replace(
            "/'nonce-[^']+'/",
            "'nonce'",
            (string) $response->assertOk()->headers->get('Content-Security-Policy'),
        );

        $this->assertSame($withoutNonce($this->get(route('home'))), $withoutNonce($this->get(route('api.docs'))));
    }

    #[Test]
    public function the_page_says_where_the_specification_is(): void
    {
        $page = $this->parse($this->get(route('api.docs'))->assertOk());
        $root = $page->getElementById('swagger-ui');

        $this->assertNotNull($root, 'There is nothing for Swagger UI to draw into.');
        $this->assertSame(route('api.docs.schema'), $root->getAttribute('data-spec-url'));
    }

    /**
     * Scripts need the nonce ('strict-dynamic' makes a browser ignore 'self' for them), and a stylesheet has to come from the site
     * (style-src names no other host).
     */
    private function assertThePageSuitsItsPolicy(TestResponse $response): void
    {
        $policy = (string) $response->assertOk()->headers->get('Content-Security-Policy');

        $this->assertSame(1, preg_match("/script-src [^;]*'nonce-([^']+)'/", $policy, $found), 'The page has no policy with a nonce.');

        $page = $this->parse($response);
        $scripts = [];
        $stylesheets = [];

        foreach ($page->getElementsByTagName('script') as $script) {
            $this->assertInstanceOf(DOMElement::class, $script);
            $this->assertSame($found[1], $script->getAttribute('nonce'), 'A script has no nonce: '.$page->saveHTML($script));

            if ($script->hasAttribute('src')) {
                $this->assertFromTheSite($script->getAttribute('src'));
                $scripts[] = basename($script->getAttribute('src'));
            }
        }

        foreach ($page->getElementsByTagName('link') as $link) {
            $this->assertInstanceOf(DOMElement::class, $link);
            $this->assertFromTheSite($link->getAttribute('href'));

            if ($link->getAttribute('rel') === 'stylesheet') {
                $stylesheets[] = basename($link->getAttribute('href'));
            }
        }

        // Without these the checks above would pass on an empty page.
        $built = $this->built();

        $this->assertSame([basename($built['file'])], $scripts, 'The page does not load the reference, or loads another script.');
        $this->assertSame(array_map(basename(...), $built['css']), $stylesheets, 'The page does not load the styles of the reference.');
    }

    private function assertFromTheSite(string $address): void
    {
        $this->assertStringStartsWith(url('/').'/', $address, 'The page loads something from another place.');
    }

    private function parse(TestResponse $response): DOMDocument
    {
        $page = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $page->loadHTML((string) $response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $page;
    }

    /**
     * What the build made of the page's script.
     *
     * @return array{file: string, css: list<string>}
     */
    private function built(): array
    {
        $manifest = json_decode((string) file_get_contents(public_path('build/manifest.json')), true);

        $this->assertArrayHasKey(self::ENTRY, $manifest, self::ENTRY.' is not in the built front end: run npm run build.');

        return $manifest[self::ENTRY];
    }
}
