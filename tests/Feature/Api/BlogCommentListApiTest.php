<?php

namespace Tests\Feature\Api;

use App\Models\Blog;
use App\Models\BlogComment;
use App\Models\BlogCommentReaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * `GET /api/v1/blogs/{blog}/comments`: the approved comments of a post, a page at a time, in the order asked for.
 *
 * The links to the other pages used to forget `per_page` and `sort` (following `links.next` from `?per_page=2&sort=newest` gave page 2 of the
 * default size in the default order), and the route is public, so nothing had looked for the key and its owner was never recognised: their own
 * comments said they could not be edited and their reactions were not shown.
 */
class BlogCommentListApiTest extends TestCase
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
     * @param  array<string, mixed>  $attributes
     */
    private function comment(Blog $blog, array $attributes = []): BlogComment
    {
        return BlogComment::factory()->for($blog)->create($attributes + ['status' => BlogComment::STATUS_APPROVED]);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function orders(): array
    {
        return [
            'oldest first, the default' => ['', 3],
            'oldest first, asked for' => ['oldest', 4],
            'newest first' => ['newest', 3],
            'most liked first' => ['top', 3],
        ];
    }

    #[Test]
    #[DataProvider('orders')]
    public function following_the_links_walks_every_comment_once_in_the_order_and_the_pages_that_were_asked_for(string $sort, int $perPage): void
    {
        $blog = Blog::factory()->published()->create();

        // Not made in the order of their times, some in the same second and some liked as often as others, so the order is the list's doing.
        $times = ['10:05', '10:01', '10:09', '10:01', '10:03', '10:09', '10:07', '10:02', '10:05', '10:04', '10:08', '10:06', '10:06'];
        $likes = [2, 0, 5, 1, 2, 5, 0, 3, 1, 2, 4, 3, 3];
        $dislikes = [0, 0, 1, 1, 0, 0, 0, 1, 0, 2, 0, 0, 0];
        $made = collect($times)->map(fn (string $time, int $i) => $this->comment($blog, [
            'created_at' => '2026-03-01 '.$time.':00',
            'like_count' => $likes[$i],
            'dislike_count' => $dislikes[$i],
        ]));

        $expected = match ($sort) {
            'newest' => $made->sort(fn (BlogComment $a, BlogComment $b) => [$b->created_at, $b->id] <=> [$a->created_at, $a->id]),
            'top' => $made->sort(fn (BlogComment $a, BlogComment $b) => [$b->like_count - $b->dislike_count, $b->like_count, $b->created_at, $b->id] <=> [$a->like_count - $a->dislike_count, $a->like_count, $a->created_at, $a->id]),
            default => $made->sort(fn (BlogComment $a, BlogComment $b) => [$a->created_at, $a->id] <=> [$b->created_at, $b->id]),
        };

        $query = http_build_query(array_filter(['per_page' => $perPage, 'sort' => $sort]));
        $page = $this->getJson(route('api.v1.blogs.comments.index', $blog).'?'.$query)->assertOk()->json();

        // Every link the page carries (first, last, previous, next, and the numbered ones in `meta.links`) keeps what was asked for.
        $links = [...array_filter($page['links']), ...array_filter(array_column($page['meta']['links'], 'url'))];
        $this->assertNotEmpty($links);

        foreach ($links as $link) {
            $this->assertStringContainsString('per_page='.$perPage, $link);

            if ($sort !== '') {
                $this->assertStringContainsString('sort='.$sort, $link);
            }
        }

        $seen = array_column($page['data'], 'id');
        $sizes = [count($seen)];

        while ($page['links']['next'] !== null) {
            $page = $this->getJson($page['links']['next'])->assertOk()->json();
            $sizes[] = count($page['data']);
            $seen = [...$seen, ...array_column($page['data'], 'id')];
        }

        $this->assertSame(array_fill(0, count($sizes) - 1, $perPage), array_slice($sizes, 0, -1));
        $this->assertSame($expected->pluck('id')->values()->all(), $seen);
    }

    #[Test]
    public function a_key_makes_the_list_say_what_its_owner_may_do_with_each_comment_and_how_they_reacted(): void
    {
        $blog = Blog::factory()->published()->create();
        $member = User::factory()->create();
        $other = User::factory()->create();

        $own = $this->comment($blog, ['user_id' => $member->id, 'created_at' => '2026-03-01 10:00:00']);
        $theirs = $this->comment($blog, ['user_id' => $other->id, 'created_at' => '2026-03-01 11:00:00']);
        BlogCommentReaction::query()->create(['blog_comment_id' => $theirs->id, 'user_id' => $member->id, 'reaction' => 'dislike']);
        BlogCommentReaction::query()->create(['blog_comment_id' => $own->id, 'user_id' => $other->id, 'reaction' => 'like']);

        $url = route('api.v1.blogs.comments.index', $blog);
        $rows = fn (array $response) => collect($response['data'])->keyBy('id');

        // Without a key nobody is recognised: nothing may be done and no reaction is shown, whoever made one.
        $guest = $rows($this->getJson($url)->assertOk()->json());
        foreach ([$own, $theirs] as $comment) {
            $this->assertSame(['can_update' => false, 'can_delete' => false, 'can_report' => false], $guest[$comment->id]['permissions']);
            $this->assertNull($guest[$comment->id]['reactions']['user_reaction']);
        }

        // With the key of the comment's author: their own comment may be changed and deleted and not reported, somebody else's the other way
        // round, and the reaction shown is theirs (on somebody else's comment) and not the one somebody else made on theirs.
        Auth::forgetGuards();
        $mine = $rows($this->getJson($url, $this->keyOf($member))->assertOk()->json());
        $this->assertSame(['can_update' => true, 'can_delete' => true, 'can_report' => false], $mine[$own->id]['permissions']);
        $this->assertNull($mine[$own->id]['reactions']['user_reaction']);
        $this->assertSame(['can_update' => false, 'can_delete' => false, 'can_report' => true], $mine[$theirs->id]['permissions']);
        $this->assertSame('dislike', $mine[$theirs->id]['reactions']['user_reaction']);

        // With the other member's key it is their comment that is theirs, and the reaction on the first is the one they made.
        Auth::forgetGuards();
        $others = $rows($this->getJson($url, $this->keyOf($other))->assertOk()->json());
        $this->assertTrue($others[$theirs->id]['permissions']['can_update']);
        $this->assertFalse($others[$own->id]['permissions']['can_update']);
        $this->assertSame('like', $others[$own->id]['reactions']['user_reaction']);
        $this->assertNull($others[$theirs->id]['reactions']['user_reaction']);
    }

    #[Test]
    public function a_key_with_the_permissions_to_edit_and_delete_comments_may_do_so_to_anybodys(): void
    {
        $blog = Blog::factory()->published()->create();
        $comment = $this->comment($blog);

        $moderator = User::factory()->create();
        $moderator->givePermissionTo(Permission::findOrCreate('blogs.acp.edit'), Permission::findOrCreate('blogs.acp.delete'));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $row = $this->getJson(route('api.v1.blogs.comments.index', $blog), $this->keyOf($moderator))->assertOk()->json('data.0');

        $this->assertSame(['can_update' => true, 'can_delete' => true, 'can_report' => true], $row['permissions']);
    }

    #[Test]
    public function a_key_that_is_not_good_is_the_same_as_none_and_not_an_error(): void
    {
        $blog = Blog::factory()->published()->create();
        $member = User::factory()->create();
        $this->comment($blog, ['user_id' => $member->id]);

        $headers = $this->keyOf($member);
        $member->tokens()->delete();

        foreach ([$headers, ['Authorization' => 'Bearer not-a-key']] as $sent) {
            $row = $this->getJson(route('api.v1.blogs.comments.index', $blog), $sent)->assertOk()->json('data.0');

            $this->assertSame(['can_update' => false, 'can_delete' => false, 'can_report' => false], $row['permissions']);
        }
    }

    #[Test]
    public function the_queries_are_the_same_for_a_page_of_any_size_with_a_key(): void
    {
        $blog = Blog::factory()->published()->create();
        $member = User::factory()->create();
        $headers = $this->keyOf($member);
        $url = route('api.v1.blogs.comments.index', $blog);

        $queries = function () use ($headers, $url): int {
            Auth::forgetGuards();
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson($url.'?per_page=50', $headers)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };

        foreach (range(1, 2) as $i) {
            $comment = $this->comment($blog, ['user_id' => $i === 1 ? $member->id : User::factory()->create()->id]);
            BlogCommentReaction::query()->create(['blog_comment_id' => $comment->id, 'user_id' => $member->id, 'reaction' => 'like']);
        }

        $queries();
        $few = $queries();

        foreach (range(1, 12) as $i) {
            $comment = $this->comment($blog, ['user_id' => $i % 2 === 0 ? $member->id : User::factory()->create()->id]);
            BlogCommentReaction::query()->create(['blog_comment_id' => $comment->id, 'user_id' => $member->id, 'reaction' => 'dislike']);
        }

        $many = $queries();

        $this->assertSame($few, $many);
        // The key (found, and marked as used), the post, the count, the page, its authors and the reactions of the key's owner, and what the
        // owner may do (a role and permission lookup, once).
        $this->assertLessThanOrEqual(10, $many);
    }
}
