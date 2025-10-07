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

    protected function normalise(?CarbonInterface $date): ?CarbonInterface
    {
        if (! $date) {
            return null;
        }

        return $date->copy()
            ->setTimezone($this->timezone)
            ->locale($this->locale);
    }

    public function iso(?CarbonInterface $date): ?string
    {
        $date = $this->normalise($date);

        return $date?->toIso8601String();
    }

    public function human(
        ?CarbonInterface $date,
        bool $absolute = false,
        bool $short = false,
        int $parts = 1,
    ): ?string {
        $date = $this->normalise($date);

        return $date?->diffForHumans(null, $absolute, $short, $parts);
    }

    public function dayDateTime(?CarbonInterface $date): ?string
    {
        $date = $this->normalise($date);

        return $date?->isoFormat('ddd, MMM D, YYYY h:mm A');
    }

    public function date(?CarbonInterface $date): ?string
    {
        $date = $this->normalise($date);

        return $date?->isoFormat('LL');
    }
}
