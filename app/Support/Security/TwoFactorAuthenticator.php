<?php

namespace App\Support\Security;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class TwoFactorAuthenticator
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const DEFAULT_DIGITS = 6;
    private const DEFAULT_PERIOD = 30;

    /**
     * Generate a new base32-encoded secret for TOTP.
     */
    public static function generateSecret(int $length = 32): string
    {
        $randomBytes = random_bytes($length);

        return self::base32Encode($randomBytes);
    }

    /**
     * Generate recovery codes for the authenticated user.
     *
     * @return list<string>
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)).'-'.Str::upper(Str::random(4)))
            ->all();
    }

    /**
     * Create an otpauth URI that can be converted into a QR code.
     */
    public static function makeQrCodeUrl(User $user, string $secret): string
    {
        $issuer = rawurlencode(config('app.name'));
        $accountName = rawurlencode($user->email ?? $user->nickname);

        return sprintf('otpauth://totp/%s:%s?secret=%s&issuer=%s', $issuer, $accountName, $secret, $issuer);
    }

    /**
     * Generate a TOTP code for the provided timestamp.
     */
    public static function code(string $secret, ?int $timestamp = null, int $digits = self::DEFAULT_DIGITS): string
    {
        $timestamp ??= Carbon::now()->getTimestamp();
        $timeSlice = (int) floor($timestamp / self::DEFAULT_PERIOD);

        return self::generateCodeForSlice($secret, $timeSlice, $digits);
    }

    /**
     * Verify a provided code against the secret.
     */
    public static function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $normalizedCode = preg_replace('/\s+/', '', $code ?? '');

        if (!preg_match('/^\d{6}$/', $normalizedCode)) {
            return false;
        }

        $timestamp ??= Carbon::now()->getTimestamp();
        $timeSlice = (int) floor($timestamp / self::DEFAULT_PERIOD);

        for ($i = -$window; $i <= $window; $i++) {
            $calculated = self::generateCodeForSlice($secret, $timeSlice + $i);

            if (hash_equals($calculated, $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decrypt a stored secret.
     */
    public static function decryptSecret(?string $encryptedSecret): ?string
    {
        if (empty($encryptedSecret)) {
            return null;
        }

        return Crypt::decryptString($encryptedSecret);
    }

    /**
     * Decrypt stored recovery codes.
     *
     * @return list<string>
     */
    public static function decryptRecoveryCodes(?string $encryptedRecoveryCodes): array
    {
        if (empty($encryptedRecoveryCodes)) {
            return [];
        }

        $decoded = Crypt::decryptString($encryptedRecoveryCodes);

        return json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Encrypt recovery codes for storage.
     */
    public static function encryptRecoveryCodes(array $recoveryCodes): string
    {
        return Crypt::encryptString(json_encode($recoveryCodes, JSON_THROW_ON_ERROR));
    }

    /**
     * Encrypt a secret for storage.
     */
    public static function encryptSecret(string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    private static function generateCodeForSlice(string $secret, int $timeSlice, int $digits = self::DEFAULT_DIGITS): string
    {
        $binarySecret = self::base32Decode($secret);
        $time = pack('N*', 0).pack('N*', $timeSlice);
        $hash = hash_hmac('sha1', $time, $binarySecret, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
        $mod = $value % (10 ** $digits);

        return str_pad((string) $mod, $digits, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $binary): string
    {
        $binaryLength = strlen($binary);
        $bits = '';

        for ($i = 0; $i < $binaryLength; $i++) {
            $bits .= str_pad(decbin(ord($binary[$i])), 8, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($bits, 5);
        $encoded = '';

        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }

            $index = bindec($chunk);
            $encoded .= self::BASE32_ALPHABET[$index];
        }

        return $encoded;
    }

    private static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $base32));
        $bits = '';

        foreach (str_split($base32) as $char) {
            $position = strpos(self::BASE32_ALPHABET, $char);

            if ($position === false) {
                continue;
            }

            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $chunks = str_split($bits, 8);
        $binary = '';

        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $binary .= chr(bindec($chunk));
            }
        }

        return $binary;
    }
}
