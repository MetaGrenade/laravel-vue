<?php

namespace App\Support\Api;

use App\Models\User;
use InvalidArgumentException;

/**
 * The scopes an API key can be limited to, from `scopes` in config/api.php.
 */
class Scopes
{
    /** Every scope, including any added later. Staff can give it; the settings page never does. */
    public const FULL = '*';

    /**
     * @return array<string, Scope>
     */
    public function all(): array
    {
        $scopes = [];

        foreach ((array) config('api.scopes', []) as $key => $definition) {
            $scopes[(string) $key] = Scope::fromConfig((string) $key, (array) $definition);
        }

        return $scopes;
    }

    /**
     * @throws InvalidArgumentException there is no such scope (a mistake in a route or the config, not something a customer did)
     */
    public function get(string $key): Scope
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("There is no API scope called [{$key}]; see scopes in config/api.php.");
    }

    /**
     * What can be told apart as a scope: every one the site has.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    /**
     * The scopes a person is offered when they make a key for themselves.
     *
     * @return array<string, Scope>
     */
    public function offeredTo(User $user): array
    {
        return array_filter(
            $this->all(),
            fn (Scope $scope) => $scope->roles === [] || $user->hasAnyRole($scope->roles),
        );
    }
}
