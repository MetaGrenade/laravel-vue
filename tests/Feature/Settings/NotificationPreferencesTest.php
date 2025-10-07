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
                'mail' => true,
                'push' => false,
                'database' => true,
            ],
        ]);

        $response->assertRedirect(route('notifications.edit'));

        $this->assertSame([
            'support_ticket' => [
                'mail' => true,
                'push' => false,
                'database' => true,
            ],
        ], $user->fresh()->notification_preferences);
    }
}
