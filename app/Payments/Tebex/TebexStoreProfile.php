<?php

namespace App\Payments\Tebex;

use App\Models\SystemSetting;

/**
 * What the shop last learned about the Tebex store it sells for, and how that store identifies its buyers.
 *
 * It is written whenever Tebex is asked who the token belongs to (a catalogue synchronisation, "Test connection") and
 * read from the shop's own database afterwards, so no page or checkout waits for Tebex to answer it.
 *
 * **A store is set up for a platform, and the platform decides what the buyer has to do before they can pay.** The list
 * below is the one Tebex's own PHP SDK uses (it refuses a platform it does not know, and so does the shop):
 *
 * - **Universal (No Auth)**: nothing. The shop makes the basket and sends the buyer to pay.
 * - **A username** (Minecraft in its three editions, Overwolf): the buyer types it at checkout and the shop gives it to
 *   Tebex when the basket is made; Tebex answers with the id it recognised it as.
 * - **Signing in** (Discord and the Steam-based games: FiveM, RedM, Rust, ARK, Garry's Mod and the rest): Tebex runs the
 *   sign-in. The shop asks Tebex where the buyer can do that for this basket, sends them there, and carries on when they
 *   come back signed in.
 *
 * Until the store has been looked at once, or if Tebex does not say, the platform is unknown and treated like a
 * universal store: there is nothing to sell yet, and a store that does need more will say so when its basket is filled.
 */
class TebexStoreProfile
{
    public const SETTING = 'tebex.store';

    /** Nothing is asked of the buyer. */
    public const NONE = 'none';

    /** The buyer types a username, which goes into the basket when it is made. */
    public const USERNAME = 'username';

    /** The buyer signs in, through a link Tebex gives for the basket. */
    public const SIGN_IN = 'sign-in';

    /**
     * Platform names as Tebex gives them (lower case), and what each asks of the buyer.
     *
     * @var array<string, string>
     */
    private const PLATFORMS = [
        'universal (no auth)' => self::NONE,

        'minecraft: java edition' => self::USERNAME,
        'minecraft (bedrock)' => self::USERNAME,
        'minecraft (offline/geyser)' => self::USERNAME,
        'overwolf' => self::USERNAME,

        'discord' => self::SIGN_IN,
        'conan exiles' => self::SIGN_IN,
        'leap' => self::SIGN_IN,
        'crey' => self::SIGN_IN,
        'onset' => self::SIGN_IN,
        'redm' => self::SIGN_IN,
        'fivem' => self::SIGN_IN,
        'gta v' => self::SIGN_IN,
        'atlas' => self::SIGN_IN,
        'space engineers' => self::SIGN_IN,
        'ark: survival evolved' => self::SIGN_IN,
        'hurtworld' => self::SIGN_IN,
        'team fortress 2' => self::SIGN_IN,
        'counter-strike: global offensive' => self::SIGN_IN,
        "garry's mod" => self::SIGN_IN,
        '7 days to die' => self::SIGN_IN,
        'rust' => self::SIGN_IN,
        'unturned' => self::SIGN_IN,
    ];

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
     * What the buyer has to do before they can pay: {@see self::NONE}, {@see self::USERNAME} or {@see self::SIGN_IN};
     * null when the store names a platform the shop does not know, and also none while it is not known at all.
     */
    public function identity(): ?string
    {
        $platform = $this->platform();

        if ($platform === null) {
            return self::NONE;
        }

        return self::PLATFORMS[mb_strtolower($platform)] ?? null;
    }

    public function needsUsername(): bool
    {
        return $this->identity() === self::USERNAME;
    }

    public function needsSignIn(): bool
    {
        return $this->identity() === self::SIGN_IN;
    }

    /**
     * What to call the username the buyer is asked for ("Minecraft username"), or null when none is asked for.
     */
    public function usernameLabel(): ?string
    {
        if (! $this->needsUsername()) {
            return null;
        }

        return str_starts_with(mb_strtolower((string) $this->platform()), 'minecraft') ? 'Minecraft username' : $this->platform().' username';
    }

    /**
     * Whether the shop knows what to ask a buyer of this store. A store set up for a platform it has never heard of is
     * not one it can sell for.
     */
    public function isSupported(): bool
    {
        return $this->identity() !== null;
    }

    /**
     * Why the shop cannot sell for the store, in words for staff, or null when it can.
     */
    public function unsupportedReason(): ?string
    {
        if ($this->isSupported()) {
            return null;
        }

        return 'This Tebex store is set up for '.$this->platform().', a platform the shop does not know how to take a buyer through yet. '
            .'It knows Universal (No Auth), Minecraft, Overwolf, Discord and the Steam-based games (FiveM, Rust and the rest).';
    }
}
