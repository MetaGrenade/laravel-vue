<?php

namespace App\Payments\Tebex;

/**
 * What Tebex says about the store the public token belongs to.
 */
final readonly class TebexStore
{
    public function __construct(
        public int $id,
        public string $name,
        public string $currency,
        public ?string $url,
        public bool $disabled,
        public bool $supportsUsernames,
        public ?string $platform,
    ) {}

    /**
     * @param  array<string, mixed>  $data  The `data` object of the store endpoint.
     */
    public static function fromApi(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            name: (string) ($data['name'] ?? ''),
            currency: strtoupper((string) ($data['currency'] ?? '')),
            url: isset($data['webstore_url']) ? (string) $data['webstore_url'] : null,
            disabled: (bool) ($data['disabled'] ?? false),
            supportsUsernames: (bool) ($data['supports_usernames'] ?? false),
            platform: isset($data['platform_type']) ? (string) $data['platform_type'] : null,
        );
    }
}
