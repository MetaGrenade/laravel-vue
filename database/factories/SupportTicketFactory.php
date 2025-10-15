<?php

namespace Database\Factories;

use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportTicket>
 */
class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'subject' => $this->faker->sentence(6),
            'body' => $this->faker->paragraph(),
            'status' => 'pending',
            'priority' => $this->faker->randomElement(['low', 'medium', 'high']),
            'support_ticket_category_id' => null,
            'support_team_id' => null,
        ];
    }
}
