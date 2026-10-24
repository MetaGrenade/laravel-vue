<?php

namespace Database\Factories;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditLog>
 */
class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'actor_id' => null,
            'actor_label' => 'Admin (admin@example.com)',
            'event' => 'users.ban',
            'outcome' => AuditLog::SUCCEEDED,
            'ip_address' => '203.0.113.7',
            'user_agent' => 'Mozilla/5.0',
            'created_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(['outcome' => AuditLog::FAILED]);
    }

    public function denied(): static
    {
        return $this->state(['outcome' => AuditLog::DENIED]);
    }
}
