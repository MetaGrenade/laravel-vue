<?php

namespace Tests\Feature\Http;

use App\Http\Middleware\ScrubMalformedInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * Bytes that are not UTF-8 in the query string or the form body are replaced with `?` before anything reads them (App\Http\Middleware\
 * ScrubMalformedInput): a JSON response or an Inertia page that echoes them back cannot be encoded, so the live search answered 500 and
 * a dozen lists came back with their page data empty. QueryStringShapesTest asks every page without a route parameter.
 */
class ScrubMalformedInputTest extends TestCase
{
    use RefreshDatabase;

    /** @return Request the request as the next middleware sees it */
    private function through(Request $request): Request
    {
        $seen = null;

        (new ScrubMalformedInput)->handle($request, function (Request $request) use (&$seen): Response {
            $seen = $request;

            return new Response;
        });

        $this->assertInstanceOf(Request::class, $seen);

        return $seen;
    }

    #[Test]
    public function invalid_bytes_in_the_query_string_become_a_question_mark(): void
    {
        $request = $this->through(Request::create('/page', 'GET', ['q' => "caf\xE9", 'list' => ['a', "b\xFF", ['deep' => "\xFE"]], "key\xFF" => 'value']));

        $this->assertSame('caf?', $request->query('q'));
        $this->assertSame(['a', 'b?', ['deep' => '?']], $request->query('list'));
        $this->assertSame('value', $request->query('key?'));
        $this->assertTrue(mb_check_encoding($request->query->all(), 'UTF-8'));
    }

    #[Test]
    public function invalid_bytes_in_a_form_become_a_question_mark(): void
    {
        $request = $this->through(Request::create('/page', 'POST', ['comment' => "caf\xE9", 'tags' => ["x\xFF"]]));

        $this->assertSame('caf?', $request->input('comment'));
        $this->assertSame(['x?'], $request->input('tags'));
    }

    #[Test]
    public function text_that_is_valid_is_left_exactly_as_it_was(): void
    {
        $sent = ['q' => 'Café 日本語 🎉', 'number' => '007', 'empty' => '', 'list' => ['  spaced  ', 'ü'], 'ünï' => 'ok'];

        $request = $this->through(Request::create('/page', 'GET', $sent));

        $this->assertSame($sent, $request->query->all());
    }

    #[Test]
    public function the_scrub_applies_to_every_route_not_only_to_the_ones_in_a_group(): void
    {
        Route::match(['GET', 'POST'], '/scrub-probe', fn (Request $request) => response()->json(['q' => $request->input('q')]));

        $this->get('/scrub-probe?q=%FF%FE')->assertOk()->assertExactJson(['q' => '??']);
        $this->post('/scrub-probe', ['q' => "x\xFF"])->assertOk()->assertExactJson(['q' => 'x?']);
    }

    #[Test]
    public function the_live_search_answers_when_the_term_is_not_utf8(): void
    {
        $response = $this->getJson('/search?q=caf%E9');

        $response->assertOk();
        $this->assertSame('caf?', $response->json('query'));
    }

    #[Test]
    public function a_list_page_that_echoes_the_filter_back_still_has_its_page_data(): void
    {
        $this->get('/blogs?search=caf%E9')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Blog')->where('filters.search', 'caf?')->etc());
    }
}
