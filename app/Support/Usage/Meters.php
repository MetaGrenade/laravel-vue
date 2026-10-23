<?php

namespace App\Support\Usage;

use InvalidArgumentException;

/**
 * The meters the site has, from `usage.meters` in config/billing.php.
 */
class Meters
{
    /**
     * @return array<string, Meter>
     */
    public function all(): array
    {
        $meters = [];

        foreach ((array) config('billing.usage.meters', []) as $key => $definition) {
            $meters[(string) $key] = Meter::fromConfig((string) $key, (array) $definition);
        }

        return $meters;
    }

    /**
     * @throws InvalidArgumentException there is no such meter (a mistake in the code or the config, not something a customer did)
     */
    public function get(string $key): Meter
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("There is no usage meter called [{$key}]; see usage.meters in config/billing.php.");
    }
}
