<?php

namespace App\Support\Localization;

use App\Models\User;
use Carbon\CarbonInterface;

class DateFormatter
{
    public function __construct(
        protected string $timezone,
        protected string $locale,
    ) {}

    public static function for(?User $user): self
    {
        return new self(
            $user?->timezone ?: PreferenceOptions::defaultTimezone(),
            $user?->locale ?: PreferenceOptions::defaultLocale(),
        );
    }

    public function timezone(): string
    {
        return $this->timezone;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function iso(?CarbonInterface $date): ?string
    {
        if (! $date) {
            return null;
        }

        return $date->copy()->setTimezone($this->timezone)->toIso8601String();
    }

    public function human(
        ?CarbonInterface $date,
        bool $absolute = false,
        bool $short = false,
        int $parts = 1,
    ): ?string {
        if (! $date) {
            return null;
        }

        return $date->copy()
            ->setTimezone($this->timezone)
            ->locale($this->locale)
            ->diffForHumans(null, $absolute, $short, $parts);
    }
}
