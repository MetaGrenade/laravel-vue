<?php

namespace Tests\Feature\Api;

use App\Models\Poll;
use App\Models\PollOption;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The API's per-minute limits (config/rate-limits.php, 'api') are each a count of their own: a call counts once against each limit
 * its route lists, and a call to one part of the API does not use up what another part has left. They used to be written as
 * `throttle:20,1`, which Laravel counts under the account alone: one count for every such limit, twice for a route with two.
 */
class ApiRateLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        return [
            'Authorization' => 'Bearer '.$user->createToken('API Client')->plainTextToken,
            'Accept' => 'application/json',
        ];
    }

    #[Test]
    public function a_route_that_lists_two_limits_counts_each_call_once_against_each(): void
    {
        // The ticket read has its own limit (30) and sits under the support limit (here 5): five reads are allowed, not three.
        config(['rate-limits.api.support' => 5, 'rate-limits.api.support_read' => 30]);

        $user = User::factory()->create();
        $ticket = SupportTicket::factory()->create(['user_id' => $user->id]);
        $headers = $this->headers($user);

        for ($call = 1; $call <= 5; $call++) {
            $this->withHeaders($headers)->getJson('/api/v1/support/tickets/'.$ticket->id)->assertOk();
        }

        $this->withHeaders($headers)->getJson('/api/v1/support/tickets/'.$ticket->id)
            ->assertStatus(429)
            ->assertJsonPath('message', 'Too Many Attempts.')
            ->assertHeader('Retry-After');
    }

    #[Test]
    public function the_limit_of_one_kind_of_call_is_its_own(): void
    {
        // Reads are allowed 3 here and the support limit 50, so it is the read limit that turns the fourth read away.
        config(['rate-limits.api.support' => 50, 'rate-limits.api.support_read' => 3]);

        $user = User::factory()->create();
        $ticket = SupportTicket::factory()->create(['user_id' => $user->id]);
        $headers = $this->headers($user);

        for ($call = 1; $call <= 3; $call++) {
            $this->withHeaders($headers)->getJson('/api/v1/support/tickets/'.$ticket->id)->assertOk();
        }

        $this->withHeaders($headers)->getJson('/api/v1/support/tickets/'.$ticket->id)->assertStatus(429);
    }

    #[Test]
    public function one_part_of_the_api_does_not_use_up_the_limit_of_another(): void
    {
        config(['rate-limits.api.polls' => 2, 'rate-limits.api.support' => 20, 'rate-limits.api.support_read' => 30]);

        $user = User::factory()->create();
        $ticket = SupportTicket::factory()->create(['user_id' => $user->id]);
        $poll = Poll::factory()->published()->create(['allow_multiple' => false]);
        $option = PollOption::factory()->create(['poll_id' => $poll->id]);
        $headers = $this->headers($user);

        // Two votes use up the polls' limit (the second is refused as a second vote, which still counts), and the third is turned away.
        $this->withHeaders($headers)->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $option->id])->assertOk();
        $this->withHeaders($headers)->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $option->id])->assertStatus(409);
        $this->withHeaders($headers)->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $option->id])->assertStatus(429);

        // The support routes are not touched by it.
        for ($call = 1; $call <= 5; $call++) {
            $this->withHeaders($headers)->getJson('/api/v1/support/tickets/'.$ticket->id)->assertOk();
        }
    }

    #[Test]
    public function the_limit_is_an_accounts_own(): void
    {
        config(['rate-limits.api.polls' => 1]);

        $first = User::factory()->create();
        $second = User::factory()->create();
        $poll = Poll::factory()->published()->create(['allow_multiple' => false]);
        $option = PollOption::factory()->create(['poll_id' => $poll->id]);
        $firstHeaders = $this->headers($first);
        $secondHeaders = $this->headers($second);

        $this->withHeaders($firstHeaders)->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $option->id])->assertOk();
        $this->withHeaders($firstHeaders)->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $option->id])->assertStatus(429);

        // The first request's account stays on the guard for the rest of the test unless it is let go.
        $this->app['auth']->forgetGuards();

        $this->withHeaders($secondHeaders)->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $option->id])->assertOk();
    }

    #[Test]
    public function two_keys_of_one_account_share_its_limit(): void
    {
        config(['rate-limits.api.polls' => 2]);

        $user = User::factory()->create();
        $poll = Poll::factory()->published()->create(['allow_multiple' => true]);
        $option = PollOption::factory()->create(['poll_id' => $poll->id]);
        $oneKey = $this->headers($user);
        $otherKey = $this->headers($user);

        $this->withHeaders($oneKey)->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $option->id])->assertOk();
        // The same option again is a conflict, which still got past the limit and counted.
        $this->withHeaders($otherKey)->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $option->id])->assertStatus(409);
        $this->withHeaders($oneKey)->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $option->id])->assertStatus(429);
    }

    #[Test]
    public function api_calls_do_not_use_up_a_limit_of_the_pages(): void
    {
        Notification::fake();
        config(['rate-limits.api.polls' => 20]);

        $user = User::factory()->unverified()->create();
        $poll = Poll::factory()->published()->create(['allow_multiple' => true]);
        $option = PollOption::factory()->create(['poll_id' => $poll->id]);

        // Ten calls to the API on the same account...
        for ($call = 1; $call <= 10; $call++) {
            $this->withHeaders($this->headers($user))->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $option->id]);
        }

        // ...leave the page's allowance of six requests for a new verification email whole.
        $this->flushHeaders();

        for ($send = 1; $send <= 6; $send++) {
            $this->actingAs($user, 'web')->post(route('verification.send'))->assertSessionDoesntHaveErrors()->assertStatus(302);
        }

        $this->actingAs($user, 'web')->post(route('verification.send'))->assertStatus(429);
    }
}
