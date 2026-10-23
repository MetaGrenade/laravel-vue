<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\User;
use App\Support\Spam\CommentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BlogCommentSpamTest extends TestCase
{
    use RefreshDatabase;

    public function test_comment_rate_limit_blocks_rapid_posts(): void
    {
        Notification::fake();

        $blog = Blog::factory()->published()->create(['comments_enabled' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);

        $limit = (int) config('rate-limits.blog_comments_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this
                ->withSession([CommentGuard::SESSION_TOKEN_KEY => 'captcha-token'])
                ->postJson(route('blogs.comments.store', ['blog' => $blog->slug]), [
                    'body' => "Comment {$i}",
                    'captcha_token' => 'captcha-token',
                    'honeypot' => '',
                ])
                ->assertCreated();
        }

        $this
            ->withSession([CommentGuard::SESSION_TOKEN_KEY => 'captcha-token'])
            ->postJson(route('blogs.comments.store', ['blog' => $blog->slug]), [
                'body' => 'Too fast',
                'captcha_token' => 'captcha-token',
                'honeypot' => '',
            ])
            ->assertStatus(429);
    }

    public function test_captcha_and_honeypot_validation_are_enforced(): void
    {
        Notification::fake();

        $blog = Blog::factory()->published()->create(['comments_enabled' => true]);
        $user = User::factory()->create();
        $this->actingAs($user);

        $session = [CommentGuard::SESSION_TOKEN_KEY => 'captcha-token'];
        $payload = [
            'body' => 'Legit comment',
            'captcha_token' => 'captcha-token',
            'honeypot' => '',
        ];

        $this
            ->withSession($session)
            ->postJson(route('blogs.comments.store', ['blog' => $blog->slug]), $payload)
            ->assertCreated();

        $this
            ->withSession($session)
            ->postJson(route('blogs.comments.store', ['blog' => $blog->slug]), array_merge($payload, [
                'honeypot' => 'bot-field',
            ]))
            ->assertStatus(422);

        $this
            ->withSession($session)
            ->postJson(route('blogs.comments.store', ['blog' => $blog->slug]), array_merge($payload, [
                'captcha_token' => 'bad-token',
            ]))
            ->assertStatus(422);
    }

    /**
     * A field that is not text (honeypot[]=x) must reach a validation error, not break the guard that reads it.
     *
     * @return array<string, array{string, string}>
     */
    public static function fieldsThatAreNotText(): array
    {
        return [
            'honeypot' => ['honeypot', 'bot-field'],
            'captcha_token' => ['captcha_token', 'captcha-token'],
        ];
    }

    #[DataProvider('fieldsThatAreNotText')]
    public function test_a_field_that_is_not_text_is_a_validation_error_not_a_server_error(string $field, string $value): void
    {
        $blog = Blog::factory()->published()->create(['comments_enabled' => true]);
        $this->actingAs(User::factory()->create());

        $this
            ->withSession([CommentGuard::SESSION_TOKEN_KEY => 'captcha-token'])
            ->postJson(route('blogs.comments.store', ['blog' => $blog->slug]), [
                'body' => 'Legit comment',
                'captcha_token' => 'captcha-token',
                'honeypot' => '',
                $field => [$value],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('blog_comments', 0);
    }

    #[DataProvider('fieldsThatAreNotText')]
    public function test_the_api_refuses_a_field_that_is_not_text_with_a_validation_error_too(string $field, string $value): void
    {
        $blog = Blog::factory()->published()->create(['comments_enabled' => true]);
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this
            ->withSession([CommentGuard::SESSION_TOKEN_KEY => 'captcha-token'])
            ->postJson(route('api.v1.blogs.comments.store', $blog), [
                'body' => 'Legit comment',
                'captcha_token' => 'captcha-token',
                'honeypot' => '',
                $field => [$value],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('blog_comments', 0);
    }
}
