<?php

namespace Tests\Feature\Auth;

use App\Support\Security\TwoFactorAuthenticator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The authenticator against the times published in RFC 6238 (appendix B, HMAC-SHA1).
 * The paper's codes are 8 digits. Signing in asks for the last 6, which is what an authenticator app shows.
 */
class TwoFactorAuthenticatorTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function publishedCodes(): array
    {
        return [
            '59 seconds' => [59, '94287082'],
            '1111111109 seconds' => [1111111109, '07081804'],
            '1111111111 seconds' => [1111111111, '14050471'],
            '1234567890 seconds' => [1234567890, '89005924'],
            '2000000000 seconds' => [2000000000, '69279037'],
            '20000000000 seconds' => [20000000000, '65353130'],
        ];
    }

    #[Test]
    #[DataProvider('publishedCodes')]
    public function a_code_matches_the_published_test_vector(int $timestamp, string $eightDigits): void
    {
        $this->assertSame($eightDigits, TwoFactorAuthenticator::code(self::SECRET, $timestamp, 8));

        $sixDigits = substr($eightDigits, -6);

        $this->assertTrue(TwoFactorAuthenticator::verify(self::SECRET, $sixDigits, 0, $timestamp));
        $this->assertFalse(TwoFactorAuthenticator::verify(self::SECRET, $eightDigits, 0, $timestamp));
    }

    #[Test]
    public function a_code_is_accepted_one_step_either_side_and_refused_beyond_that(): void
    {
        $code = TwoFactorAuthenticator::code(self::SECRET, 59, 6);

        $this->assertTrue(TwoFactorAuthenticator::verify(self::SECRET, '287 082', 1, 59));
        $this->assertTrue(TwoFactorAuthenticator::verify(self::SECRET, $code, 1, 59 - 30));
        $this->assertTrue(TwoFactorAuthenticator::verify(self::SECRET, $code, 1, 59 + 30));
        $this->assertFalse(TwoFactorAuthenticator::verify(self::SECRET, $code, 1, 59 + 90));
        $this->assertFalse(TwoFactorAuthenticator::verify(self::SECRET, '000000', 1, 59));
    }

    #[Test]
    public function a_set_that_is_short_or_unreadable_asks_for_a_new_one(): void
    {
        $full = TwoFactorAuthenticator::encryptRecoveryCodes(array_fill(0, TwoFactorAuthenticator::RECOVERY_SET_SIZE, 'AAAA-BBBB-CCCC'));

        $this->assertFalse(TwoFactorAuthenticator::needsNewRecoveryCodes($full));
        $this->assertTrue(TwoFactorAuthenticator::needsNewRecoveryCodes(TwoFactorAuthenticator::encryptRecoveryCodes(['AAAA-BBBB-CCCC'])));
        $this->assertTrue(TwoFactorAuthenticator::needsNewRecoveryCodes(null));
        $this->assertTrue(TwoFactorAuthenticator::needsNewRecoveryCodes(''));
        $this->assertTrue(TwoFactorAuthenticator::needsNewRecoveryCodes('not-encrypted'));
    }
}
