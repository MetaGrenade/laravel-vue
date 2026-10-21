<?php

namespace App\Support\Entitlements;

/**
 * What an owner may use right now: the features switched on and the limits raised, by key. Built by {@see Entitlements};
 * it does not change once built.
 */
final class EntitlementSet
{
    /**
     * @param  list<string>  $features  Keys of the features that are on.
     * @param  array<string, int>  $limits  Key => how much, the grants added up.
     */
    public function __construct(
        private readonly array $features = [],
        private readonly array $limits = [],
    ) {}

    /**
     * Whether the owner has it: a feature that is on, or a limit above nothing.
     */
    public function has(string $key): bool
    {
        return in_array($key, $this->features, true) || $this->limit($key) > 0;
    }

    /**
     * How much of a limit the owner has. Nothing (0) for a key they do not hold, which is what makes a limit safe to
     * check without asking whether it exists.
     */
    public function limit(string $key): int
    {
        return $this->limits[$key] ?? 0;
    }

    /**
     * @return list<string>
     */
    public function features(): array
    {
        return $this->features;
    }

    /**
     * @return array<string, int>
     */
    public function limits(): array
    {
        return $this->limits;
    }

    public function isEmpty(): bool
    {
        return $this->features === [] && $this->limits === [];
    }

    /**
     * Both sets together: a feature that is on in either is on, and limits add up.
     */
    public function merge(self $other): self
    {
        $limits = $this->limits;

        foreach ($other->limits as $key => $value) {
            $limits[$key] = ($limits[$key] ?? 0) + $value;
        }

        return new self(array_values(array_unique([...$this->features, ...$other->features])), $limits);
    }

    /**
     * For the browser.
     *
     * @return array{features: list<string>, limits: array<string, int>}
     */
    public function toArray(): array
    {
        return ['features' => $this->features, 'limits' => $this->limits];
    }
}
