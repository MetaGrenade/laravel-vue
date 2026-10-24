<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\AuditLogAccount;
use App\Models\User;
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

    /**
     * Concerning these accounts, as an entry about their accounts is.
     */
    public function about(User ...$users): static
    {
        return $this->afterCreating(function (AuditLog $log) use ($users) {
            foreach ($users as $user) {
                AuditLogAccount::query()->create(['audit_log_id' => $log->id, 'owner_type' => User::class, 'owner_id' => $user->id]);
            }
        });
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
