<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\V1\PollController;
use App\Models\Blog;
use App\Models\Poll;
use App\Models\PollOption;
use App\Models\PollVote;
use App\Models\User;
use App\Support\OpenApi\Specification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `GET /api/v1/polls`: the published and closed polls, a page at a time, newest first.
 *
 * It used to return every poll in one answer (1.6 MB and 330 ms at 3,000 polls, for anybody, with no key), and it never said what the key's
 * owner had voted for, though the API document says it does: the two routes are public, so nothing had looked for the key.
 */
class PollListApiTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, string>
     */
    private function keyOf(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('API Client')->plainTextToken, 'Accept' => 'application/json'];
    }

    /**
     * A published poll, with options, made at a given time.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function poll(string $at, array $attributes = [], int $options = 2): Poll
    {
        $poll = Poll::factory()->published()->create($attributes + ['created_at' => $at, 'updated_at' => $at]);
        PollOption::factory()->count($options)->create(['poll_id' => $poll->id]);

        return $poll;
    }

    private function vote(User $user, Poll $poll, PollOption $option): void
    {
        PollVote::query()->create(['poll_id' => $poll->id, 'poll_option_id' => $option->id, 'user_id' => $user->id, 'vote_key' => 0]);
    }

    /**
     * @return list<int>
     */
    private function idsOnPage(string $query = ''): array
    {
        return array_column($this->getJson('/api/v1/polls?'.$query)->assertOk()->json('polls'), 'id');
    }

    #[Test]
    public function the_polls_come_a_page_at_a_time_newest_first_with_the_links_and_meta_of_the_other_lists(): void
    {
        // Made in an order that is not the order of their times, so that newest first is the list's doing and not the order of the ids.
        $minutes = [7, 3, 15, 1, 20, 11, 5, 18, 2, 9, 14, 6, 19, 4, 13, 8, 17, 10, 16, 12];
        $polls = collect($minutes)->mapWithKeys(fn (int $minute) => [$minute => $this->poll(sprintf('2026-03-01 10:%02d:00', $minute))]);
        $newestFirst = $polls->sortKeysDesc()->pluck('id')->values()->all();

        $response = $this->getJson('/api/v1/polls')->assertOk();

        $this->assertSame(array_slice($newestFirst, 0, PollController::PER_PAGE), array_column($response->json('polls'), 'id'));
        $this->assertSame(1, $response->json('meta.current_page'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertSame(PollController::PER_PAGE, $response->json('meta.per_page'));
        $this->assertSame(20, $response->json('meta.total'));
        $this->assertNull($response->json('links.prev'));
        $this->assertSame(url('/api/v1/polls?page=2'), $response->json('links.next'));

        $this->assertSame(array_slice($newestFirst, PollController::PER_PAGE), $this->idsOnPage('page=2'));
    }

    #[Test]
    public function it_has_the_same_links_and_meta_as_the_list_of_blog_posts(): void
    {
        Blog::factory()->published()->create();
        $this->poll('2026-03-01 10:00:00');

        $blogs = $this->getJson('/api/v1/blogs')->assertOk();
        $polls = $this->getJson('/api/v1/polls')->assertOk();

        $this->assertSame(array_keys($blogs->json('links')), array_keys($polls->json('links')));
        $this->assertSame(array_keys($blogs->json('meta')), array_keys($polls->json('meta')));
        $this->assertSame(['polls', 'links', 'meta'], array_keys($polls->json()));
    }

    #[Test]
    public function the_size_of_a_page_can_be_asked_for_and_is_held_to_between_one_and_fifty(): void
    {
        foreach (range(1, 60) as $minute) {
            $this->poll(sprintf('2026-03-01 %02d:%02d:00', intdiv($minute, 60) + 10, $minute % 60));
        }

        $this->assertCount(3, $this->idsOnPage('per_page=3'));
        $this->assertCount(50, $this->idsOnPage('per_page=50'));
        $this->assertCount(50, $this->idsOnPage('per_page=100'));
        $this->assertCount(1, $this->idsOnPage('per_page=0'));
        $this->assertCount(1, $this->idsOnPage('per_page=lots'));
        $this->assertCount(PollController::PER_PAGE, $this->idsOnPage());

        $this->assertSame(3, $this->getJson('/api/v1/polls?per_page=3')->json('meta.per_page'));
        $this->assertSame(20, $this->getJson('/api/v1/polls?per_page=3')->json('meta.last_page'));
    }

    #[Test]
    public function polls_made_in_the_same_second_are_each_on_one_page_and_never_two_or_none(): void
    {
        $made = collect(range(1, 8))->map(fn () => $this->poll('2026-03-01 10:00:00'))->pluck('id')->all();

        $paged = [...$this->idsOnPage('per_page=3'), ...$this->idsOnPage('per_page=3&page=2'), ...$this->idsOnPage('per_page=3&page=3')];

        $this->assertCount(8, $paged);
        $this->assertEqualsCanonicalizing($made, $paged);
        // The later one first, as for any other poll.
        $this->assertSame(array_reverse($made), $paged);
    }

    #[Test]
    public function drafts_and_scheduled_polls_are_neither_listed_nor_counted_and_closed_ones_are(): void
    {
        $published = $this->poll('2026-03-01 10:00:00');
        $closed = Poll::factory()->closed()->create(['created_at' => '2026-03-01 11:00:00']);
        Poll::factory()->create(['created_at' => '2026-03-01 12:00:00']);

        $response = $this->getJson('/api/v1/polls')->assertOk();

        $this->assertSame([$closed->id, $published->id], array_column($response->json('polls'), 'id'));
        $this->assertSame(2, $response->json('meta.total'));
    }

    #[Test]
    public function a_page_past_the_end_is_empty_and_still_says_how_many_there_are(): void
    {
        $this->poll('2026-03-01 10:00:00');

        $response = $this->getJson('/api/v1/polls?page=9')->assertOk();

        $this->assertSame([], $response->json('polls'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    #[Test]
    public function a_poll_is_written_as_it_always_was(): void
    {
        $voter = User::factory()->create();
        $poll = $this->poll('2026-03-01 10:00:00', ['title' => 'Best colour', 'slug' => 'best-colour', 'allow_multiple' => false], options: 0);
        [$red, $blue] = [PollOption::factory()->create(['poll_id' => $poll->id, 'label' => 'Red', 'sort_order' => 1]), PollOption::factory()->create(['poll_id' => $poll->id, 'label' => 'Blue', 'sort_order' => 2])];
        $this->vote($voter, $poll, $red);
        $this->vote(User::factory()->create(), $poll, $red);
        $this->vote(User::factory()->create(), $poll, $blue);

        $row = $this->getJson('/api/v1/polls')->assertOk()->json('polls.0');

        $this->assertSame(
            ['id', 'title', 'slug', 'description', 'status', 'allow_multiple', 'starts_at', 'ends_at', 'total_votes', 'user_vote_option_ids', 'can_vote', 'options'],
            array_keys($row),
        );
        $this->assertSame(3, $row['total_votes']);
        $this->assertSame(
            [['id' => $red->id, 'label' => 'Red', 'votes_count' => 2, 'vote_percent' => 66.7], ['id' => $blue->id, 'label' => 'Blue', 'votes_count' => 1, 'vote_percent' => 33.3]],
            $row['options'],
        );
        $this->assertSame([], $row['user_vote_option_ids']);
        $this->assertTrue($row['can_vote']);
    }

    #[Test]
    public function a_key_makes_the_list_say_what_its_owner_voted_for_and_whether_they_still_can(): void
    {
        $member = User::factory()->create();
        $other = User::factory()->create();
        $voted = $this->poll('2026-03-01 11:00:00');
        $notVoted = $this->poll('2026-03-01 10:00:00');
        $votedFor = $voted->options()->first();
        $this->vote($member, $voted, $votedFor);
        $this->vote($other, $notVoted, $notVoted->options()->first());

        // Without a key nobody is recognised.
        $guest = $this->getJson('/api/v1/polls')->assertOk()->json('polls');
        $this->assertSame([[], []], array_column($guest, 'user_vote_option_ids'));
        $this->assertSame([true, true], array_column($guest, 'can_vote'));

        Auth::forgetGuards();
        $rows = collect($this->getJson('/api/v1/polls', $this->keyOf($member))->assertOk()->json('polls'))->keyBy('id');

        // What they voted for, and not what somebody else did; and a single-choice poll they voted in is one they can no longer vote in.
        $this->assertSame([$votedFor->id], $rows[$voted->id]['user_vote_option_ids']);
        $this->assertFalse($rows[$voted->id]['can_vote']);
        $this->assertSame([], $rows[$notVoted->id]['user_vote_option_ids']);
        $this->assertTrue($rows[$notVoted->id]['can_vote']);
    }

    #[Test]
    public function a_key_makes_a_single_poll_say_what_its_owner_voted_for(): void
    {
        $member = User::factory()->create();
        $poll = $this->poll('2026-03-01 10:00:00');
        $option = $poll->options()->first();
        $this->vote($member, $poll, $option);

        $this->getJson('/api/v1/polls/'.$poll->slug)->assertOk()->assertJsonPath('poll.user_vote_option_ids', [])->assertJsonPath('poll.can_vote', true);

        Auth::forgetGuards();
        $this->getJson('/api/v1/polls/'.$poll->slug, $this->keyOf($member))->assertOk()
            ->assertJsonPath('poll.user_vote_option_ids', [$option->id])
            ->assertJsonPath('poll.can_vote', false);
    }

    #[Test]
    public function the_api_document_describes_the_pages_the_list_comes_in(): void
    {
        $document = Specification::toArray();
        $operation = $document['paths']['/v1/polls']['get'];

        $parameters = collect($operation['parameters'])->keyBy('name');
        $this->assertSame(['page', 'per_page'], $parameters->keys()->all());
        $this->assertSame(['type' => 'integer', 'minimum' => 1, 'maximum' => PollController::MAX_PER_PAGE, 'default' => PollController::PER_PAGE], $parameters['per_page']['schema']);

        $schemaName = basename($operation['responses']['200']['content']['application/json']['schema']['$ref']);
        $schema = $document['components']['schemas'][$schemaName];

        // `polls` where the other lists say `data`, and the rest the same as theirs.
        $this->assertSame(['polls', 'links', 'meta'], array_keys($schema['properties']));
        $this->assertSame(['polls', 'links', 'meta'], $schema['required']);
        $this->assertSame(['$ref' => '#/components/schemas/Poll'], $schema['properties']['polls']['items']);
        $this->assertSame(['data', 'links', 'meta'], array_keys($document['components']['schemas']['PaginatedBlogCollection']['properties']));
    }

    #[Test]
    public function a_key_that_is_not_good_is_the_same_as_none_and_not_an_error(): void
    {
        $this->poll('2026-03-01 10:00:00');
        $member = User::factory()->create();
        $headers = $this->keyOf($member);
        $member->tokens()->delete();

        $this->getJson('/api/v1/polls', $headers)->assertOk()->assertJsonCount(1, 'polls');
        $this->getJson('/api/v1/polls', ['Authorization' => 'Bearer not-a-key'])->assertOk()->assertJsonCount(1, 'polls');
    }

    #[Test]
    public function only_the_polls_of_the_page_are_read_and_the_queries_are_the_same_for_a_page_of_any_size(): void
    {
        $member = User::factory()->create();
        $headers = $this->keyOf($member);

        foreach (range(1, 40) as $minute) {
            $poll = $this->poll(sprintf('2026-03-01 10:%02d:00', $minute), options: 4);
            $this->vote($member, $poll, $poll->options()->first());
        }

        $read = 0;
        Poll::retrieved(function () use (&$read) {
            $read++;
        });

        $queries = function (string $query) use ($headers): int {
            Auth::forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/polls?'.$query, $headers)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        $queries('per_page=1');
        $read = 0;

        $one = $queries('per_page=1');
        $this->assertSame(1, $read);

        $read = 0;
        $many = $queries('per_page=30');

        // 40 polls, 30 of them on the page; and what is asked for them is asked once, whatever the page.
        $this->assertSame(30, $read);
        $this->assertSame($one, $many);
        // The key (found, and marked as used), the count, the page, the options and the votes of its owner.
        $this->assertLessThanOrEqual(6, $many);
    }
}
