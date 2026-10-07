<?php

namespace App\Payments;

use App\Models\SystemSetting;
use App\Payments\Contracts\PaymentProvider;
use App\Payments\Exceptions\PaymentException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves payment providers by key and knows which one the store uses.
 */
class PaymentManager
{
    public const SETTING_KEY = 'commerce.provider';

    public function __construct(private readonly Container $container) {}

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys((array) config('commerce.providers', []));
    }

    /**
     * Keys with labels, for the admin settings screen.
     *
     * @return list<array{key: string, label: string, configured: bool}>
     */
    public function options(): array
    {
        return array_map(function (string $key) {
            $provider = $this->provider($key);

            return ['key' => $key, 'label' => $provider->label(), 'configured' => $provider->isConfigured()];
        }, $this->keys());
    }

    /**
     * The key chosen in the ACP, falling back to configuration. An unknown
     * stored value (a provider that was removed) falls back too.
     */
    public function activeKey(): string
    {
        $stored = SystemSetting::get(self::SETTING_KEY);

        if (is_string($stored) && in_array($stored, $this->keys(), true)) {
            return $stored;
        }

        return (string) config('commerce.provider', 'stripe');
    }

    public function active(): PaymentProvider
    {
        return $this->provider($this->activeKey());
    }

    /**
     * Webhook routes and reconciliation use this: an order is always handled by
     * the provider it was paid through, whatever the store uses today.
     */
    public function provider(string $key): PaymentProvider
    {
        $class = config("commerce.providers.{$key}");

        if (! is_string($class)) {
            throw new PaymentException("Unknown payment provider [{$key}].");
        }

        $provider = $this->container->make($class);

        if (! $provider instanceof PaymentProvider) {
            throw new PaymentException("[{$class}] does not implement the payment provider contract.");
        }

        return $provider;
    }
}
