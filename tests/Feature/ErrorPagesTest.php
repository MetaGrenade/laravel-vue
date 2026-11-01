<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\SystemSetting;
use App\Models\User;
use App\Support\Errors\ErrorPages;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;
use Tests\TestCase;

/**
 * What a visit inside the app sees when the server refuses it (docs/error-pages.md): the Error page for a visit that asks for a page,
 * a notice on the same page for one that does something, and the standalone page for everything that is not a visit.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // In debug a server error is Laravel's own page; the cases below are the ones a visitor sees.
        config(['app.debug' => false]);

        Route::middleware('web')->group(function () {
            Route::get('/_errors/missing', fn () => abort(404));
            Route::get('/_errors/forbidden', fn () => abort(403));
            Route::get('/_errors/bound/{user}', fn (User $user) => 'ok');
            Route::get('/_errors/said', fn () => abort(404, 'The thread called Secret plans is gone.'));
            Route::get('/_errors/reason', fn () => abort(403, 'This thread is locked.'));
            Route::get('/_errors/policy', fn () => throw new AuthorizationException('Only the author may do this.'));
            Route::get('/_errors/broken', fn () => throw new RuntimeException('secret detail about the database'));
            Route::get('/_errors/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
            Route::get('/_errors/later/{seconds}', fn (int $seconds) => abort(503, '', ['Retry-After' => $seconds]));
            Route::get('/_errors/teapot', fn () => abort(418));
            Route::get('/_errors/throttled', fn () => 'ok')->middleware('throttle:1,1');

            Route::post('/_errors/missing', fn () => abort(404));
            Route::post('/_errors/reason', fn () => abort(403, 'This thread is locked.'));
            Route::post('/_errors/broken', fn () => throw new RuntimeException('secret detail about the database'));
            Route::post('/_errors/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
            Route::post('/_errors/invalid', fn () => throw ValidationException::withMessages(['title' => 'Give it a title.']));
            Route::post('/_errors/throttled', fn () => 'ok')->middleware('throttle:1,1');
            Route::delete('/_errors/missing', fn () => abort(404));
            Route::put('/_errors/missing', fn () => abort(404));
        });
    }

    /** The headers the Inertia client sends with a visit; a stale version is what a tab that has been open through a deploy sends. */
    private function visit(?string $version = null): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version ?? (string) app(HandleInertiaRequests::class)->version(request()),
            'Accept' => 'text/html, application/xhtml+xml',
        ];
    }

    public function test_a_page_that_is_not_there_is_the_error_page_with_the_status(): void
    {
        $this->get('/_errors/missing', $this->visit())
            ->assertStatus(404)
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'Error')
            ->assertJsonPath('props.status', 404)
            ->assertJsonPath('props.hint', 'This page is not on the site.')
            ->assertJsonPath('props.detail', null)
            ->assertJsonPath('props.retryable', false)
            ->assertJsonPath('props.labels.home', 'Go to the homepage')
            ->assertJsonPath('url', '/_errors/missing');
    }

    public function test_the_error_page_has_the_data_every_page_has(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/_errors/missing', $this->visit())
            ->assertJsonPath('props.name', config('app.name'))
            ->assertJsonPath('props.auth.user.id', $user->id)
            ->assertJsonStructure(['props' => ['flash', 'translations', 'ziggy', 'locale']])
            ->assertJsonPath('version', (string) app(HandleInertiaRequests::class)->version(request()));
    }

    public function test_a_record_that_is_not_there_is_the_error_page_too(): void
    {
        $this->get(route('blogs.view', ['slug' => 'no-such-post']), $this->visit())
            ->assertStatus(404)
            ->assertJsonPath('component', 'Error')
            ->assertJsonPath('props.status', 404);
    }

    public function test_a_record_that_route_binding_cannot_find_has_the_shared_data_too(): void
    {
        // Route model binding fails before the Inertia middleware has run, so nothing is shared yet: the Error page is built through the
        // middleware, or the layout would have no member, no translations and no routes.
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/_errors/bound/999999', $this->visit())
            ->assertStatus(404)
            ->assertJsonPath('component', 'Error')
            ->assertJsonPath('props.name', config('app.name'))
            ->assertJsonPath('props.auth.user.id', $user->id)
            ->assertJsonStructure(['props' => ['flash', 'translations', 'ziggy']]);
    }

    public function test_a_page_the_member_may_not_open_is_the_error_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/acp', $this->visit())
            ->assertStatus(403)
            ->assertJsonPath('component', 'Error')
            ->assertJsonPath('props.status', 403)
            ->assertJsonPath('props.hint', 'Your account cannot open this page.');
    }

    public function test_the_reason_given_to_abort_is_shown_and_a_policy_message_is_not(): void
    {
        $this->get('/_errors/reason', $this->visit())->assertJsonPath('props.detail', 'This thread is locked.');
        $this->get('/_errors/forbidden', $this->visit())->assertJsonPath('props.detail', null);
        $this->get('/_errors/policy', $this->visit())->assertStatus(403)->assertJsonPath('props.detail', null);
    }

    public function test_only_a_refusal_has_a_reason_to_show(): void
    {
        // Whatever was given to abort() for a page that is not there is for the log, not for the page.
        $this->get('/_errors/said', $this->visit())->assertStatus(404)->assertJsonPath('props.detail', null);
    }

    public function test_a_server_error_does_not_say_what_broke(): void
    {
        $response = $this->get('/_errors/broken', $this->visit())
            ->assertStatus(500)
            ->assertJsonPath('component', 'Error')
            ->assertJsonPath('props.status', 500)
            ->assertJsonPath('props.retryable', true)
            ->assertJsonPath('props.detail', null);

        $this->assertStringNotContainsString('secret detail', $response->getContent());
    }

    public function test_a_server_error_while_debugging_is_left_to_laravel(): void
    {
        config(['app.debug' => true]);

        $response = $this->get('/_errors/broken', $this->visit())->assertStatus(500);

        $this->assertNull($response->headers->get('X-Inertia'));
        $this->assertStringContainsString('secret detail', $response->getContent());
    }

    public function test_a_page_with_a_missing_record_while_debugging_is_still_the_error_page(): void
    {
        config(['app.debug' => true]);

        $this->get('/_errors/missing', $this->visit())->assertStatus(404)->assertJsonPath('component', 'Error');
    }

    public function test_too_many_requests_says_when_to_try_again_and_keeps_the_header(): void
    {
        $this->get('/_errors/throttled', $this->visit())->assertOk();

        $response = $this->get('/_errors/throttled', $this->visit())
            ->assertStatus(429)
            ->assertJsonPath('component', 'Error')
            ->assertJsonPath('props.retryable', true);

        $this->assertGreaterThan(0, (int) $response->headers->get('Retry-After'));
        $this->assertMatchesRegularExpression('/^Try again in (a second|\d+ seconds)\.$/', $response->json('props.detail'));
        $this->assertNotNull($response->headers->get('X-RateLimit-Limit'));
    }

    public function test_the_wait_is_said_in_seconds_and_then_in_minutes(): void
    {
        $this->get('/_errors/later/1', $this->visit())
            ->assertStatus(503)
            ->assertHeader('Retry-After', '1')
            ->assertJsonPath('props.retryable', true)
            ->assertJsonPath('props.detail', 'Try again in a second.');
        $this->get('/_errors/later/45', $this->visit())->assertJsonPath('props.detail', 'Try again in 45 seconds.');
        $this->get('/_errors/later/120', $this->visit())->assertJsonPath('props.detail', 'Try again in 120 seconds.');
        $this->get('/_errors/later/121', $this->visit())->assertJsonPath('props.detail', 'Try again in 3 minutes.');
        $this->get('/_errors/later/3600', $this->visit())->assertJsonPath('props.detail', 'Try again in 60 minutes.');
        $this->get('/_errors/later/0', $this->visit())->assertJsonPath('props.detail', null);
    }

    public function test_an_action_that_is_refused_goes_back_to_the_page_with_a_notice(): void
    {
        $this->from('/blogs')
            ->post('/_errors/missing', [], $this->visit())
            ->assertStatus(303)
            ->assertRedirect('/blogs')
            ->assertSessionHasErrors(['refused' => 'That is no longer there. It may have been removed.']);
    }

    public function test_the_client_is_told_to_ask_again_with_get_whatever_the_method_was(): void
    {
        // A 302 after a DELETE would make the client repeat the DELETE at the page it is sent back to.
        $this->from('/blogs')->delete('/_errors/missing', [], $this->visit())->assertStatus(303)->assertRedirect('/blogs');
        $this->from('/blogs')->put('/_errors/missing', [], $this->visit())->assertStatus(303)->assertRedirect('/blogs');
    }

    public function test_a_refused_action_with_a_reason_gives_the_reason(): void
    {
        $this->from('/forum')
            ->post('/_errors/reason', [], $this->visit())
            ->assertStatus(303)
            ->assertSessionHasErrors(['refused' => 'This thread is locked.']);
    }

    public function test_a_failed_action_does_not_say_what_broke(): void
    {
        $this->from('/forum')
            ->post('/_errors/broken', [], $this->visit())
            ->assertStatus(303)
            ->assertSessionHasErrors(['refused' => 'Something went wrong on our side. Try again in a moment.']);
    }

    public function test_an_expired_session_goes_back_with_a_notice(): void
    {
        $this->from('/settings/profile')
            ->post('/_errors/expired', [], $this->visit())
            ->assertStatus(303)
            ->assertRedirect('/settings/profile')
            ->assertSessionHasErrors(['refused' => 'Your session ended, so that was not saved. Try again.']);
    }

    public function test_throttled_actions_go_back_with_a_notice(): void
    {
        $this->from('/blogs')->post('/_errors/throttled', [], $this->visit())->assertOk();

        $this->from('/blogs')
            ->post('/_errors/throttled', [], $this->visit())
            ->assertStatus(303)
            ->assertSessionHasErrors(['refused' => 'Too many attempts. Wait a moment, then try again.']);
    }

    public function test_the_page_it_goes_back_to_has_the_notice_as_an_error_and_not_as_a_success(): void
    {
        $this->from('/blogs')->post('/_errors/missing', [], $this->visit());

        // An error on the page is what keeps the client from calling the visit's onSuccess, where a form clears what was typed and a
        // draft is deleted: it is a visit that did not work, like a form with mistakes, and onError runs instead. The toast is made from
        // it (resources/js/lib/flashToast.ts), so it is not also a flash message, which would be shown as a success.
        $this->get('/blogs', $this->visit())
            ->assertJsonPath('props.errors.refused', 'That is no longer there. It may have been removed.')
            ->assertJsonPath('props.flash.error', null)
            ->assertJsonPath('props.flash.success', null);
    }

    public function test_a_form_with_mistakes_still_shows_them_on_the_form(): void
    {
        // Validation is not an error page: a 302 back with the errors, as before.
        $this->from('/forum')
            ->post('/_errors/invalid', [], $this->visit())
            ->assertRedirect('/forum')
            ->assertSessionHasErrors(['title' => 'Give it a title.']);
    }

    public function test_a_status_without_words_is_left_as_it_was(): void
    {
        $this->get('/_errors/teapot', $this->visit())->assertStatus(418)->assertHeaderMissing('X-Inertia');
    }

    public function test_every_status_has_words_for_the_page_and_the_notice(): void
    {
        foreach (ErrorPages::STATUSES as $status) {
            $this->assertTrue(trans()->has("errors.hints.{$status}"), "errors.hints.{$status}");
            $this->assertTrue(trans()->has("errors.actions.{$status}"), "errors.actions.{$status}");
        }
    }

    public function test_an_address_no_route_has_is_loaded_in_full(): void
    {
        // No session was started for it, so there is nothing for the layout to show: the browser asks for the address itself and gets
        // the standalone page. Nobody is told about a failure: asking for the page is not one, it is a missing address.
        Exceptions::fake();

        $this->get('/no-such-page', $this->visit())
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/no-such-page'));

        Exceptions::assertNothingReported();
    }

    public function test_an_action_at_an_address_no_route_has_stays_as_it_was(): void
    {
        // No session, so no page to go back to and nowhere to leave a notice.
        $this->post('/no-such-page', [], $this->visit())->assertStatus(404)->assertHeaderMissing('X-Inertia');
    }

    public function test_a_tab_from_before_a_deploy_loads_the_page_in_full(): void
    {
        $this->get('/_errors/missing', $this->visit('an-older-build'))
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/_errors/missing'));
    }

    public function test_a_page_that_cannot_be_built_does_not_become_a_second_error(): void
    {
        // Something every page shares fails while the Error page is built.
        Inertia::share('broken', fn () => throw new RuntimeException('shared data failed'));
        Exceptions::fake();

        $this->get('/_errors/missing', $this->visit())
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/_errors/missing'));

        // It is not swallowed: whoever runs the site hears that the Error page failed.
        Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'shared data failed');
    }

    public function test_a_page_load_still_gets_the_standalone_page(): void
    {
        $this->get('/_errors/missing')
            ->assertStatus(404)
            ->assertHeaderMissing('X-Inertia')
            ->assertSee('This page is not on the site.')
            ->assertSee('Go to the homepage');

        $this->get('/_errors/reason')->assertStatus(403)->assertSee('Your account cannot open this page.')->assertSee('This thread is locked.');
        $this->get('/_errors/broken')->assertStatus(500)->assertDontSee('secret detail');
    }

    public function test_a_request_for_json_still_gets_json(): void
    {
        $this->getJson('/_errors/missing')->assertStatus(404)->assertJsonStructure(['message']);
        $this->getJson('/api/no-such-endpoint')->assertStatus(404);
    }

    public function test_maintenance_mode_loads_the_page_in_full_for_a_visit_and_is_the_page_otherwise(): void
    {
        SystemSetting::set('maintenance_mode', true);

        $this->get(route('home'), $this->visit())
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', route('home'));

        $this->get(route('home'))->assertStatus(503)->assertHeaderMissing('X-Inertia');
    }

    public function test_an_action_made_when_maintenance_starts_loads_the_page_it_was_made_from(): void
    {
        SystemSetting::set('maintenance_mode', true);

        // Loading the address of an action in full is a GET, and a POST-only address is refused as the wrong method before
        // maintenance mode is asked.
        $this->from('/forum')
            ->post('/_errors/missing', [], $this->visit())
            ->assertStatus(409)
            ->assertHeader('X-Inertia-Location', url('/forum'));
    }
}
