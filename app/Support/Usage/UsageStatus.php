<?php

namespace App\Support\Usage;

/**
 * Where one account stands on one meter in a month: what it has used, and what it may.
 */
final readonly class UsageStatus
{
    public function __construct(
        public Meter $meter,
        public int $used,
        /** What it may use in the month, or null for no limit. */
        public ?int $allowance,
        public UsagePeriod $period,
    ) {}

    public function remaining(): ?int
    {
        return $this->allowance === null ? null : max(0, $this->allowance - $this->used);
    }

    /** Whether it has used all it may. */
    public function exhausted(): bool
    {
        return $this->allowance !== null && $this->used >= $this->allowance;
    }

    /** Whether the meter refuses more: it is used up, and the meter's `overage` is `block`. */
    public function blocks(): bool
    {
        return $this->exhausted() && $this->meter->overage === Meter::BLOCK;
    }

    /**
     * @return array{meter: string, label: string, unit: string, used: int, allowance: int|null, remaining: int|null, exhausted: bool, blocks: bool, resets_at: string}
     */
    public function toArray(): array
    {
        return [
            'meter' => $this->meter->key,
            'label' => $this->meter->label,
            'unit' => $this->meter->unit,
            'used' => $this->used,
            'allowance' => $this->allowance,
            'remaining' => $this->remaining(),
            'exhausted' => $this->exhausted(),
            'blocks' => $this->blocks(),
            'resets_at' => $this->period->end->toIso8601String(),
        ];
    }
}
