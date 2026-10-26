# Two-factor authentication

A member can require a code from an authenticator app at sign-in, and can keep recovery codes for when that app is gone.

> **Status:** built: authenticator codes, recovery codes, a prompt to replace a set that is no longer complete, and a limit on guesses. **Not built:** see [below](#not-built).

## Using it

**Settings → Security.** Turning it on shows a secret. Confirming with a 6-digit code from an authenticator app turns it on and makes eight recovery codes. Each recovery code works once. Generating a new set replaces the old one, so the codes written down before that no longer work.

The codes follow the published test times for this kind of authenticator (RFC 6238, SHA-1). A code from the 30 seconds just before or just after is accepted. Anything else is refused, including a code that is not six digits.

Signing in with a recovery code uses one of the set. Until the member generates a new set of eight, a bar at the top of the site asks them to. The same bar shows when the stored set cannot be read. It is not shown to someone who has not turned this on, and it is not shown while a member of staff is viewing the site as that member.

Guessing is limited on the challenge itself: five tries a minute for that sign-in, and ten a minute from the same place. A recovery code is the same challenge, so it shares the limit.

## Not built

- **Passkeys.** They are deferred.
- **A new set made on its own.** The member generates it, and is shown the new codes. Nothing replaces them in the background.
- **A reminder before a code has been used.** A full set of eight is quiet.

## Testing

`tests/Feature/Auth/TwoFactorAuthenticatorTest.php`: the published times produce the published codes, a code is accepted one step either side and refused beyond that, and a short or unreadable set asks for a new one.

`tests/Feature/Auth/RecoveryCodePromptTest.php`: a short set shows the prompt on the dashboard and on the security page, signing in with a recovery code shows it, generating a new set clears it, a full set and an account without this turned on do not, and viewing as the member hides it.

`tests/Feature/Auth/RateLimitingTest.php`: a sixth guess of an authenticator code, and of a recovery code, is refused.
