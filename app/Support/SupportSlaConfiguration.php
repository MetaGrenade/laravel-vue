<?php

namespace App\Support;

use App\Models\SystemSetting;

class SupportSlaConfiguration
{
    public const SETTING_KEY = 'support.sla';

    public const PRIORITIES = ['low', 'medium', 'high'];

    /**
     * Retrieve the merged SLA configuration, combining defaults with overrides.
     */
    public static function all(): array
    {
        $defaults = config('support.sla', []);
        $overrides = SystemSetting::get(self::SETTING_KEY, []);

        if (! is_array($overrides)) {
            $overrides = [];
        }

        return array_replace_recursive($defaults, $overrides);
    }

    /**
     * Retrieve the configured priority escalation rules.
     */
    public static function priorityEscalations(): array
    {
        $sla = static::all();
        $escalations = $sla['priority_escalations'] ?? [];

        return is_array($escalations) ? $escalations : [];
    }

    /**
     * Retrieve the reassignment thresholds per priority.
     */
    public static function reassignAfter(): array
    {
        $sla = static::all();
        $thresholds = $sla['reassign_after'] ?? [];

        return is_array($thresholds) ? $thresholds : [];
    }

    /**
     * Persist the SLA configuration overrides.
     */
    public static function update(array $payload): void
    {
        $normalized = [
            'priority_escalations' => [],
            'reassign_after' => [],
        ];

        foreach (self::PRIORITIES as $priority) {
            $rule = $payload['priority_escalations'][$priority] ?? [];

            $normalized['priority_escalations'][$priority] = [
                'after' => $rule['after'] ?? null,
                'to' => $rule['to'] ?? null,
            ];

            $normalized['reassign_after'][$priority] = $payload['reassign_after'][$priority] ?? null;
        }

        SystemSetting::set(self::SETTING_KEY, $normalized);
    }
}
