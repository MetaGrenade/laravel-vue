<?php

namespace Spatie\Activitylog;

use Closure;

class ActivityLogStatus
{
    protected bool $enabled;

    public function __construct(bool $enabled = true)
    {
        $this->enabled = $enabled;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function disable(): void
    {
        $this->enabled = false;
    }

    public function enable(): void
    {
        $this->enabled = true;
    }

    public function temporarilyDisable(Closure $callback): mixed
    {
        $previous = $this->enabled;

        $this->disable();

        try {
            return $callback();
        } finally {
            if ($previous) {
                $this->enable();
            }
        }
    }
}
