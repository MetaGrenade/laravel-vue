<?php

namespace App\Support\Api;

/**
 * Something an API key can be limited to (see `scopes` in config/api.php).
 */
final readonly class Scope
{
    /**
     * @param  list<string>  $roles  Only people with one of these roles are offered it (none listed: everybody).
     */
    public function __construct(
        public string $key,
        public string $label,
        public string $description,
        public array $roles,
    ) {}

    /**
     * @param  array<string, mixed>  $definition
     */
    public static function fromConfig(string $key, array $definition): self
    {
        return new self(
            $key,
            (string) ($definition['label'] ?? $key),
            (string) ($definition['description'] ?? ''),
            array_values(array_map('strval', (array) ($definition['roles'] ?? []))),
        );
    }

    /**
     * @return array{key: string, label: string, description: string}
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'label' => $this->label, 'description' => $this->description];
    }
}
