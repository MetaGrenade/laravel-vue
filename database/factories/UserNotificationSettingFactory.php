<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserNotificationSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserNotificationSetting>
 */
class UserNotificationSettingFactory extends Factory
{
    protected $model = UserNotificationSetting::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'category' => $this->faker->randomElement(array_keys(config('notification-preferences.categories'))),
            'channel_mail' => true,
            'channel_push' => true,
            'channel_database' => true,
        ];
    }
}
