<?php

namespace Tests\Unit\Support;

use App\Models\User;
use App\Support\NotificationChannelPreferences;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationChannelPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_normalize_converts_varied_values_to_booleans(): void
    {
        $normalized = NotificationChannelPreferences::normalize('support_ticket', [
            'mail' => 'false',
            'push' => 'on',
            'database' => 0,
        ]);

        $this->assertSame([
            'mail' => false,
            'push' => true,
            'database' => false,
        ], $normalized);
    }

    public function test_toggles_returns_stored_preferences(): void
    {
        $user = User::factory()->create([
            'notification_preferences' => [
                'support_ticket' => [
                    'mail' => false,
                    'push' => true,
                    'database' => false,
                ],
            ],
        ]);

        $this->assertSame([
            'mail' => false,
            'push' => true,
            'database' => false,
        ], NotificationChannelPreferences::toggles($user, 'support_ticket'));
    }
}
