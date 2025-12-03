<?php

namespace Tests\Feature\Blog;

use App\Models\Blog;
use App\Models\User;
use App\Support\Spam\CommentGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
}
