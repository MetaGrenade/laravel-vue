<?php

namespace Database\Factories;

use App\Models\ModerationAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ModerationAction>
 */
class ModerationActionFactory extends Factory
{
    protected $model = ModerationAction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'actor_id' => User::factory(),
            'type' => ModerationAction::WARNING,
            'reason' => fake()->sentence(),
            'notified' => true,
        ];
    }

    public function ban(?\DateTimeInterface $until = null): static
    {
        return $this->state(fn () => ['type' => ModerationAction::BAN, 'expires_at' => $until]);
    }

    public function unban(): static
    {
        return $this->state(fn () => ['type' => ModerationAction::UNBAN]);
    }

    public function removal(): static
    {
        return $this->state(fn () => [
            'type' => ModerationAction::REMOVAL,
            'notified' => false,
            'details' => ['threads_hidden' => 1, 'threads_rejected' => 0, 'replies' => 2, 'comments' => 0, 'reviews' => 0, 'reports' => 0],
        ]);
    }
}
