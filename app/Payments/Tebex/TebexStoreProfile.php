<?php

namespace App\Payments\Tebex;

use App\Models\SystemSetting;

/**
 * What the shop last learned about the Tebex store it sells for, and whether it can sell for it.
 *
 * It is written whenever Tebex is asked who the token belongs to (a catalogue synchronisation, "Test connection") and
 * read from the shop's own database afterwards, so no page or checkout waits for Tebex to answer it.
 *
 * **The shop can only sell for a store whose platform is "Universal (No Auth)".** Every other platform (Minecraft, the
 * Steam games such as Rust or FiveM, Discord, Overwolf and the rest) needs the buyer to sign in or give a username and
 * authorise the basket before it can be paid, and the shop does not do that step. Tebex's own PHP SDK treats Universal
 * (No Auth) as the only platform that needs none, and refuses a platform it does not know; so does the shop.
 *
 * Until the store has been looked at once the platform is unknown, and nothing is refused: there is nothing to sell yet.
 */
class TebexStoreProfile
{
    public const SETTING = 'tebex.store';

    /** The one platform Tebex's SDK knows to need no basket authorisation. */
    public const SUPPORTED_PLATFORM = 'Universal (No Auth)';

    /** @var array<string, mixed>|null */
    private ?array $profile = null;

    private bool $loaded = false;

    /**
     * Keep what Tebex said about the store.
     */
    public function remember(TebexStore $store): void
    {
        $profile = [
            'name' => $store->name,
            'currency' => $store->currency,
            'platform' => $store->platform,
            'url' => $store->url,
            'disabled' => $store->disabled,
            'checked_at' => now()->toIso8601String(),
        ];

        SystemSetting::set(self::SETTING, $profile);

        $this->profile = $profile;
        $this->loaded = true;
    }

    /**
     * @return array<string, mixed>|null Null until the store has been looked at.
     */
    public function current(): ?array
    {
        if (! $this->loaded) {
            $stored = SystemSetting::get(self::SETTING);
            $this->profile = is_array($stored) ? $stored : null;
            $this->loaded = true;
        }

        return $this->profile;
    }

    public function platform(): ?string
    {
        $platform = $this->current()['platform'] ?? null;

        return is_string($platform) && trim($platform) !== '' ? trim($platform) : null;
    }

    /**
     * Whether the shop can sell for the store: its platform needs no sign-in, or is not known yet.
     */
    public function isSupported(): bool
    {
        $platform = $this->platform();

        return $platform === null || mb_strtolower($platform) === mb_strtolower(self::SUPPORTED_PLATFORM);
    }

    /**
     * Why the shop cannot sell for the store, in words for staff, or null when it can.
     */
    public function unsupportedReason(): ?string
    {
        if ($this->isSupported()) {
            return null;
        }

        return 'This Tebex store is set up for '.$this->platform().', where buyers have to sign in or give a username before they can pay, '
            .'and the shop does not do that step yet. It can only sell for a store whose platform is '.self::SUPPORTED_PLATFORM.'.';
    }
}
