<?php

namespace Tests\Feature\Forum;

use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\User;
use App\Notifications\ForumPostMentioned;
use App\Support\Forum\ForumPublisher;
use App\Support\Security\HtmlSanitizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A nickname is anything up to 255 characters that nobody else has (`Ada Lovelace`, `Zoë`, `Łukasz`, `Ada.`), and a post names a member with
 * `@nickname`. The editor writes the exact name into the node it makes, `<a data-type="mention" data-nickname="Ada Lovelace">@Ada Lovelace</a>`,
 * and the server read only the text, for a name of ASCII letters, digits and `_.-`: `@Ada Lovelace` named `Ada` (a different member, if there is one),
 * `@Zoë` named `Zo`, and `@Ada.` at the end of a sentence named `Ada.`, which nobody is.
 */
class MentionNicknamesTest extends TestCase
{
    use RefreshDatabase;

    // --- What the editor writes ----------------------------------------------------------------------------

    #[Test]
    public function a_member_with_a_space_in_the_nickname_is_mentioned_by_the_editors_node(): void
    {
        $author = User::factory()->create();
        $ada = User::factory()->create(['nickname' => 'Ada Lovelace']);

        $this->assertSame([$ada->id], $this->mentioned($this->node($ada).' can you look?', $author));
    }

    #[Test]
    public function the_member_named_in_the_node_is_told_and_not_one_whose_name_is_its_first_word(): void
    {
        $author = User::factory()->create();
        $ada = User::factory()->create(['nickname' => 'Ada']);
        $lovelace = User::factory()->create(['nickname' => 'Ada Lovelace']);

        $this->assertSame([$lovelace->id], $this->mentioned($this->node($lovelace).' can you look?', $author));
        $this->assertSame([$ada->id], $this->mentioned($this->node($ada).' can you look?', $author));
    }

    #[Test]
    public function the_node_without_a_profile_link_and_a_name_with_an_ampersand_or_a_letter_with_a_mark_are_read_too(): void
    {
        $author = User::factory()->create();
        $tom = User::factory()->create(['nickname' => 'Tom & Jerry']);
        $zoe = User::factory()->create(['nickname' => 'Zoë Łukasz']);

        $body = $this->stored('<p>Hello <span data-type="mention" data-id="'.$tom->id.'" data-nickname="Tom &amp; Jerry">@Tom &amp; Jerry</span> and '
            .'<span data-type="mention" data-id="'.$zoe->id.'" data-nickname="Zoë Łukasz">@Zoë Łukasz</span></p>');

        $this->assertEqualsCanonicalizing([$tom->id, $zoe->id], $this->resolve($body, $author)->pluck('id')->all());
    }

    #[Test]
    public function a_node_is_believed_only_when_what_it_shows_is_the_name_it_carries(): void
    {
        $author = User::factory()->create();
        $bob = User::factory()->create(['nickname' => 'Bob']);
        $carol = User::factory()->create(['nickname' => 'Carol']);

        // It says Bob and carries Carol: a reader sees Bob, so Bob is who is told, and the name in the attribute is not used.
        $body = $this->stored('<p><a data-type="mention" data-id="'.$carol->id.'" data-nickname="Carol" href="https://example.test/m/'.$carol->id.'">@Bob</a> hi</p>');

        $this->assertSame([$bob->id], $this->resolve($body, $author)->pluck('id')->all());
    }

    #[Test]
    public function a_node_that_is_not_believed_does_not_stop_the_ones_after_it(): void
    {
        $author = User::factory()->create();
        $bob = User::factory()->create(['nickname' => 'Bob']);
        $carol = User::factory()->create(['nickname' => 'Carol Ann']);
        $dan = User::factory()->create(['nickname' => 'Dan Brown']);

        $body = $this->stored('<p><a data-type="mention" data-nickname="Carol Ann">@Bob</a> and '.$this->node($carol).' and <span data-type="mention" data-nickname="">@x</span> and '.$this->node($dan).'</p>');

        $this->assertEqualsCanonicalizing([$bob->id, $carol->id, $dan->id], $this->resolve($body, $author)->pluck('id')->all());
    }

    #[Test]
    public function a_node_shows_the_name_it_carries_whatever_the_case_or_the_spacing(): void
    {
        $author = User::factory()->create();
        $ada = User::factory()->create(['nickname' => 'Ada Lovelace']);

        // What a reader sees may differ from the name in the attribute only in case and in the space between words, and the name is what is looked up.
        $body = $this->stored('<p><a data-type="mention" data-nickname=" Ada Lovelace ">@ada  lovelace</a> hi</p>');

        $this->assertSame([$ada->id], $this->resolve($body, $author)->pluck('id')->all());
    }

    #[Test]
    public function a_typed_name_is_two_to_fifty_characters_and_a_mark_is_one_of_them(): void
    {
        $author = User::factory()->create();
        $short = User::factory()->create(['nickname' => 'Al']);
        $one = User::factory()->create(['nickname' => 'A']);
        $fifty = User::factory()->create(['nickname' => str_repeat('n', 50)]);
        $over = User::factory()->create(['nickname' => str_repeat('o', 51)]);
        $decomposed = User::factory()->create(['nickname' => "Zoe\u{0308}"]);

        $found = $this->resolve($this->stored('<p>@Al @A @'.str_repeat('n', 50).' @'.str_repeat('o', 51)." @Zoe\u{0308}</p>"), $author)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$short->id, $fifty->id, $decomposed->id], $found, 'one character is too short and 51 is too long, 2 and 50 are not, and a letter with a mark is one name');
        $this->assertNotContains($one->id, $found);
        $this->assertNotContains($over->id, $found);
    }

    #[Test]
    public function a_typed_name_may_have_digits_a_hyphen_and_an_underscore(): void
    {
        $author = User::factory()->create();
        $robot = User::factory()->create(['nickname' => 'R2D2']);
        $jean = User::factory()->create(['nickname' => 'Jean-Luc']);
        $first = User::factory()->create(['nickname' => 'Jean']);
        $under = User::factory()->create(['nickname' => 'a_b']);

        $found = $this->resolve($this->stored('<p>@R2D2 and @Jean-Luc and @a_b</p>'), $author)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$robot->id, $jean->id, $under->id], $found, 'a hyphen is part of a name and Jean is not told');
        $this->assertNotContains($first->id, $found);
    }

    #[Test]
    public function a_node_with_an_empty_name_names_nobody(): void
    {
        $author = User::factory()->create();
        User::factory()->create(['nickname' => '']);

        $this->assertSame([], $this->resolve($this->stored('<p>Hi <span data-type="mention" data-nickname="">@</span></p>'), $author)->pluck('id')->all());
    }

    #[Test]
    public function a_node_ends_the_text_before_it_and_after_it(): void
    {
        $author = User::factory()->create();
        $ada = User::factory()->create(['nickname' => 'Ada Lovelace']);
        $bob = User::factory()->create(['nickname' => 'Bob']);

        $found = $this->resolve($this->stored('<p>mail'.$this->node($ada).'@Bob</p>'), $author)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$ada->id, $bob->id], $found, 'a name typed right after a node is a name, and the node is a member and not a word in the middle of a longer one');
    }

    #[Test]
    public function a_dot_after_a_one_character_name_does_not_make_a_name_of_one_character(): void
    {
        $author = User::factory()->create();
        User::factory()->create(['nickname' => 'A']);

        $this->assertSame([], $this->resolve($this->stored('<p>Thanks @A.</p>'), $author)->pluck('id')->all());
    }

    #[Test]
    public function a_node_with_no_name_is_read_as_the_text_it_shows(): void
    {
        $author = User::factory()->create();
        $bob = User::factory()->create(['nickname' => 'Bob']);

        $body = $this->stored('<p><span data-type="mention">@Bob</span> hi</p>');

        $this->assertSame([$bob->id], $this->resolve($body, $author)->pluck('id')->all());
    }

    #[Test]
    public function the_node_of_the_author_is_not_a_mention_and_does_not_fall_back_to_anybody_else(): void
    {
        $author = User::factory()->create(['nickname' => 'Ada Lovelace']);
        User::factory()->create(['nickname' => 'Ada']);

        $this->assertSame([], $this->mentioned($this->node($author).' note to self', $author));
    }

    // --- What is typed -------------------------------------------------------------------------------------

    #[Test]
    public function a_name_typed_with_a_letter_that_is_not_ascii_is_a_mention(): void
    {
        $author = User::factory()->create();
        $zoe = User::factory()->create(['nickname' => 'Zoë']);
        $lukasz = User::factory()->create(['nickname' => 'Łukasz']);
        $sven = User::factory()->create(['nickname' => 'Sven_Åke']);

        $found = $this->resolve($this->stored('<p>Thanks @Zoë and @Łukasz, and @Sven_Åke too</p>'), $author)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$zoe->id, $lukasz->id, $sven->id], $found);
    }

    #[Test]
    public function punctuation_after_a_typed_name_is_not_part_of_it(): void
    {
        $author = User::factory()->create();
        $ada = User::factory()->create(['nickname' => 'Ada']);

        foreach (['Thanks @Ada.', 'Thanks @Ada...', 'Thanks @Ada,', '(@Ada)', 'Thanks @Ada-', 'Is it @Ada?', '@Ada: look'] as $text) {
            $this->assertSame([$ada->id], $this->resolve($this->stored('<p>'.$text.'</p>'), $author)->pluck('id')->all(), $text);
        }
    }

    #[Test]
    public function a_name_that_ends_in_a_dot_is_still_a_name_and_the_longest_one_wins(): void
    {
        $author = User::factory()->create();
        $withDot = User::factory()->create(['nickname' => 'Ada.']);
        $without = User::factory()->create(['nickname' => 'Ada']);

        $this->assertSame([$withDot->id], $this->resolve($this->stored('<p>Thanks @Ada.</p>'), $author)->pluck('id')->all(), 'a member called Ada. is who it says');
        $this->assertSame([$without->id], $this->resolve($this->stored('<p>Thanks @Ada</p>'), $author)->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$withDot->id, $without->id], $this->resolve($this->stored('<p>Thanks @Ada. and @Ada</p>'), $author)->pluck('id')->all());
    }

    #[Test]
    public function the_author_is_never_told_and_a_shorter_name_is_not_taken_in_their_place(): void
    {
        $author = User::factory()->create(['nickname' => 'Ada.']);
        User::factory()->create(['nickname' => 'Ada']);

        $this->assertSame([], $this->resolve($this->stored('<p>Thanks @Ada.</p>'), $author)->pluck('id')->all());
    }

    #[Test]
    public function a_typed_name_next_to_a_node_and_the_same_member_twice_are_found_once_each(): void
    {
        $author = User::factory()->create();
        $ada = User::factory()->create(['nickname' => 'Ada Lovelace']);
        $bob = User::factory()->create(['nickname' => 'Bob']);

        $body = $this->stored('<p>'.$this->node($ada).' and @Bob, and again '.$this->node($ada).' and @bob</p>');

        $this->assertEqualsCanonicalizing([$ada->id, $bob->id], $this->resolve($body, $author)->pluck('id')->all());
    }

    #[Test]
    public function an_address_is_still_not_a_mention(): void
    {
        $author = User::factory()->create();
        User::factory()->create(['nickname' => 'Ada']);

        $this->assertSame([], $this->resolve($this->stored('<p>Write to me@Ada.example or x@Ada</p>'), $author)->pluck('id')->all());
    }

    #[Test]
    public function the_text_of_a_believed_node_is_not_read_again_as_a_typed_name(): void
    {
        $author = User::factory()->create();
        User::factory()->create(['nickname' => 'Ada']);
        $zoe = User::factory()->create(['nickname' => 'Ada Zoe']);

        $this->assertSame([$zoe->id], $this->mentioned($this->node($zoe), $author), 'the first word of a name is not a second member');
    }

    // --- The whole way -------------------------------------------------------------------------------------

    #[Test]
    public function a_reply_that_names_a_member_with_a_space_in_the_nickname_tells_them(): void
    {
        Notification::fake();

        [$board, $thread] = $this->forumContext();
        $replier = User::factory()->create();
        $ada = User::factory()->create(['nickname' => 'Ada Lovelace']);
        User::factory()->create(['nickname' => 'Ada']);

        $this->actingAs($replier)->post(route('forum.posts.store', [$board, $thread]), [
            'body' => '<p>Thanks '.$this->node($ada).' for looking</p>',
        ])->assertRedirect();

        $post = ForumPost::query()->where('forum_thread_id', $thread->id)->latest('id')->firstOrFail();

        $this->assertDatabaseCount('forum_post_mentions', 1);
        $this->assertDatabaseHas('forum_post_mentions', ['forum_post_id' => $post->id, 'mentioned_user_id' => $ada->id]);
        Notification::assertSentTo($ada, ForumPostMentioned::class);
    }

    // --- Helpers --------------------------------------------------------------------------------------------

    private function node(User $member): string
    {
        return '<a data-type="mention" data-id="'.$member->id.'" data-nickname="'.e($member->nickname).'" href="https://example.test/members/'.$member->id.'">@'.e($member->nickname).'</a>';
    }

    /**
     * @return list<int>
     */
    private function mentioned(string $html, User $author): array
    {
        return $this->resolve($this->stored('<p>'.$html.'</p>'), $author)->pluck('id')->all();
    }

    /**
     * @return Collection<int, User>
     */
    private function resolve(string $stored, User $author): Collection
    {
        return app(ForumPublisher::class)->resolveMentionedUsers($stored, $author->id);
    }

    private function stored(string $html): string
    {
        return app(HtmlSanitizer::class)->forum($html);
    }

    /**
     * @return array{ForumBoard, ForumThread}
     */
    private function forumContext(): array
    {
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'General', 'position' => 1]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'Announcements', 'slug' => 'announcements', 'description' => 'Announcements', 'position' => 1]);
        $starter = User::factory()->create();

        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $starter->id,
            'title' => 'Thread Title',
            'slug' => 'thread-title',
            'excerpt' => null,
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
            'last_posted_at' => now(),
            'last_post_user_id' => $starter->id,
        ]);

        ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $starter->id, 'body' => '<p>Thread opener</p>']);

        return [$board, $thread];
    }
}
