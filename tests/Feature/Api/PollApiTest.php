<?php

namespace Tests\Feature\Api;

use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PollApiTest extends TestCase
{
    use RefreshDatabase;

    private function tokenHeaders(User $user): array
    {
        $token = $user->createToken('API Client')->plainTextToken;

        return [
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ];
    }

    public function test_it_lists_published_and_closed_polls(): void
    {
        Poll::factory()->published()->create(['title' => 'Published poll']);
        Poll::factory()->closed()->create(['title' => 'Closed poll']);
        Poll::factory()->create(['title' => 'Draft poll']);

        $response = $this->getJson('/api/v1/polls');

        $response->assertOk()
            ->assertJsonCount(2, 'polls')
            ->assertJsonFragment(['title' => 'Published poll'])
            ->assertJsonFragment(['title' => 'Closed poll'])
            ->assertJsonMissing(['title' => 'Draft poll']);
    }

    public function test_it_shows_poll_details(): void
    {
        $poll = Poll::factory()->published()->create();
        PollOption::factory()->count(2)->create(['poll_id' => $poll->id]);

        $this->getJson('/api/v1/polls/'.$poll->slug)
            ->assertOk()
            ->assertJsonPath('poll.id', $poll->id)
            ->assertJsonCount(2, 'poll.options');
    }

    public function test_single_choice_poll_prevents_second_vote(): void
    {
        $user = User::factory()->create();
        $poll = Poll::factory()->published()->create(['allow_multiple' => false]);
        $options = PollOption::factory()->count(2)->create(['poll_id' => $poll->id]);
        $headers = $this->tokenHeaders($user);

        $this->withHeaders($headers)
            ->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $options[0]->id])
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $options[1]->id])
            ->assertStatus(409);

        $this->assertSame(1, PollVote::query()->count());
    }

    public function test_multi_choice_poll_allows_multiple_options(): void
    {
        $user = User::factory()->create();
        $poll = Poll::factory()->published()->create(['allow_multiple' => true]);
        $options = PollOption::factory()->count(2)->create(['poll_id' => $poll->id]);
        $headers = $this->tokenHeaders($user);

        $this->withHeaders($headers)
            ->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $options[0]->id])
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $options[1]->id])
            ->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/v1/polls/'.$poll->slug.'/vote', ['option_id' => $options[0]->id])
            ->assertStatus(409);

        $this->assertSame(2, PollVote::query()->count());
    }
}
