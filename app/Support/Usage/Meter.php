<?php

namespace App\Support\Usage;

/**
 * Something that is counted (see `usage.meters` in config/billing.php).
 */
final readonly class Meter
{
    /** A request over the allowance is refused. */
    public const BLOCK = 'block';

    /** Carry on counting past the allowance. */
    public const ALLOW = 'allow';

    /**
     * @param  string|null  $entitlement  The limit entitlement whose amount, given by an account's plans, is what it may use each month.
     * @param  int|null  $freeAllowance  What an account whose plans give none may use each month (null: no limit, it is only counted).
     * @param  self::BLOCK|self::ALLOW  $overage  What happens when the allowance is used up.
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $unit,
        public ?string $entitlement,
        public ?int $freeAllowance,
        public string $overage,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function fromConfig(string $key, array $definition): self
    {
        $free = $definition['free_allowance'] ?? null;

        return new self(
            $key,
            (string) ($definition['label'] ?? $key),
            (string) ($definition['unit'] ?? 'use'),
            filled($definition['entitlement'] ?? null) ? (string) $definition['entitlement'] : null,
            is_numeric($free) ? max(0, (int) $free) : null,
            ($definition['overage'] ?? self::BLOCK) === self::ALLOW ? self::ALLOW : self::BLOCK,
        );
    }
}
