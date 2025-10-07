<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_notification_preferences(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $response = $this->put(route('notifications.update'), [
            'channels' => [
                'support_ticket' => [
                    'mail' => true,
                    'push' => false,
                    'database' => true,
                ],
                'forum_subscription' => [
                    'mail' => false,
                    'push' => true,
                    'database' => true,
                ],
                'blog_subscription' => [
                    'mail' => true,
                    'push' => false,
                    'database' => false,
                ],
            ],
        ]);

        $response->assertRedirect(route('notifications.edit'));

        $this->assertSame([
            'support_ticket' => [
                'mail' => true,
                'push' => false,
                'database' => true,
            ],
            'forum_subscription' => [
                'mail' => false,
                'push' => true,
                'database' => true,
            ],
            'blog_subscription' => [
                'mail' => true,
                'push' => false,
                'database' => false,
            ],
        ], $user->fresh()->notification_preferences);
    }

    public function test_user_can_update_notification_preferences_with_string_values(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [
                'support_ticket' => [
                    'mail' => true,
                    'push' => true,
                    'database' => true,
                ],
                'forum_subscription' => [
                    'mail' => true,
                    'push' => true,
                    'database' => true,
                ],
                'blog_subscription' => [
                    'mail' => true,
                    'push' => true,
                    'database' => true,
                ],
            ],
        ]);

        $this->actingAs($user);

        $response = $this->put(route('notifications.update'), [
            'channels' => [
                'support_ticket' => [
                    'mail' => 'off',
                    'push' => '1',
                    'database' => '0',
                ],
                'forum_subscription' => [
                    'mail' => 'false',
                    'push' => 'on',
                    'database' => true,
                ],
                'blog_subscription' => [
                    'mail' => false,
                    'push' => 'true',
                    'database' => 'false',
                ],
            ],
        ]);

        $response->assertRedirect(route('notifications.edit'));

        $this->assertSame([
            'support_ticket' => [
                'mail' => false,
                'push' => true,
                'database' => false,
            ],
            'forum_subscription' => [
                'mail' => false,
                'push' => true,
                'database' => true,
            ],
            'blog_subscription' => [
                'mail' => false,
                'push' => true,
                'database' => false,
            ],
        ], $user->fresh()->notification_preferences);
    }
}
